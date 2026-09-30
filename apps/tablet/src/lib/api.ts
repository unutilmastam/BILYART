import { clock } from './clock';

/** Uzbek message the server would use; shown when the network itself fails. */
export const OFFLINE_MESSAGE = "Aloqa yo'q. Iltimos, kuting.";

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
  ) {
    super(message);
    this.name = 'ApiError';
  }

  get isNetwork(): boolean {
    return this.status === 0;
  }
}

let token: string | null = null;

/** The paired tablet's bearer token (kept in memory; persisted by the pairing feature). */
export function setToken(value: string | null): void {
  token = value;
}

export interface RequestOptions {
  method?: 'GET' | 'POST';
  body?: unknown;
  form?: FormData;
  /** Same key on every retry of one logical action (server replays the first result). */
  idempotencyKey?: string;
  /** Override the Authorization header (pairing poll token). */
  authorization?: string;
}

export async function api<T>(path: string, opts: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json';
  if (opts.authorization) headers.Authorization = opts.authorization;
  else if (token) headers.Authorization = `Bearer ${token}`;
  if (opts.idempotencyKey) headers['Idempotency-Key'] = opts.idempotencyKey;

  const sentAt = Date.now();
  let response: Response;
  try {
    response = await fetch(`/api${path}`, {
      method: opts.method ?? 'GET',
      headers,
      body: opts.form ?? (opts.body !== undefined ? JSON.stringify(opts.body) : undefined),
      credentials: 'omit',
      cache: 'no-store',
    });
  } catch {
    throw new ApiError(0, 'NETWORK_ERROR', OFFLINE_MESSAGE);
  }
  const receivedAt = Date.now();

  if (response.status === 204) return undefined as T;
  let data: unknown = null;
  try {
    data = await response.json();
  } catch {
    // non-JSON answer (proxy error page …) is handled as a server error below
  }
  if (data && typeof data === 'object' && 'serverTime' in data && typeof data.serverTime === 'string') {
    clock.sample(data.serverTime, sentAt, receivedAt);
  }
  if (!response.ok) {
    const err = (data as { error?: { code?: string; message?: string } } | null)?.error;
    throw new ApiError(response.status, err?.code ?? 'SERVER_ERROR', err?.message ?? "Nimadir xato ketdi. Qayta urinib ko'ring.");
  }
  return data as T;
}

export function newKey(): string {
  return crypto.randomUUID();
}

/**
 * Same-origin JSON client for the Laravel API (session cookie + CSRF).
 * Every error becomes an ApiError carrying the stable `code` and the Uzbek
 * `message` produced by the server (docs/API.md §1) — never raw internals.
 */

export interface ApiErrorBody {
  error: { code: string; message: string; fields?: Record<string, string[]>; requestId?: string };
}

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly fields: Record<string, string[]> = {},
    public readonly requestId?: string,
  ) {
    super(message);
    this.name = 'ApiError';
  }

  fieldError(name: string): string | undefined {
    return this.fields[name]?.[0];
  }
}

export const NETWORK_ERROR_CODE = 'NETWORK_ERROR';

function readCookie(name: string): string | undefined {
  const match = document.cookie.split('; ').find((c) => c.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.slice(name.length + 1)) : undefined;
}

let csrfReady: Promise<void> | null = null;

async function ensureCsrf(): Promise<void> {
  if (readCookie('XSRF-TOKEN')) return;
  csrfReady ??= fetch('/api/auth/csrf', { credentials: 'same-origin' }).then(() => undefined);
  await csrfReady;
  csrfReady = null;
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  query?: Record<string, string | number | undefined | null>;
  /** Idempotency-Key for state-changing requests; generated when omitted for non-GET calls. */
  idempotencyKey?: string;
}

export function newIdempotencyKey(): string {
  return crypto.randomUUID();
}

export async function api<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const method = options.method ?? 'GET';
  const url = new URL(`/api${path}`, window.location.origin);
  for (const [k, v] of Object.entries(options.query ?? {})) {
    if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v));
  }

  const headers: Record<string, string> = { Accept: 'application/json' };
  if (method !== 'GET') {
    await ensureCsrf();
    const xsrf = readCookie('XSRF-TOKEN');
    if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;
    headers['Idempotency-Key'] = options.idempotencyKey ?? newIdempotencyKey();
  }
  if (options.body !== undefined) headers['Content-Type'] = 'application/json';

  let response: Response;
  try {
    response = await fetch(url.pathname + url.search, {
      method,
      headers,
      credentials: 'same-origin',
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
    });
  } catch {
    throw new ApiError(0, NETWORK_ERROR_CODE, "Aloqa yo'q. Internetni tekshirib, qayta urinib ko'ring.");
  }

  if (response.status === 204) return undefined as T;

  const data: unknown = await response.json().catch(() => null);
  if (!response.ok) {
    const body = data as Partial<ApiErrorBody> | null;
    const err = body?.error;
    throw new ApiError(
      response.status,
      err?.code ?? 'SERVER_ERROR',
      err?.message ?? "Nimadir xato ketdi. Qayta urinib ko'ring.",
      err?.fields ?? {},
      err?.requestId,
    );
  }
  return data as T;
}

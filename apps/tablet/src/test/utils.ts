import { vi } from 'vitest';

type Handler = (url: string, init: RequestInit) => { status: number; body?: unknown } | 'network-error';

/** Replaces fetch with a tiny router: { 'GET /api/tablet/bootstrap': () => ({status, body}) }. Records calls. */
export function mockApi(handlers: Record<string, Handler>) {
  const calls: { method: string; path: string; init: RequestInit }[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => {
      const url = String(input);
      const method = (init.method ?? 'GET').toUpperCase();
      const path = url.split('?')[0]!;
      calls.push({ method, path, init });
      const handler = handlers[`${method} ${path}`];
      const res = handler ? handler(url, init) : { status: 404, body: { error: { code: 'NOT_FOUND', message: 'x' } } };
      if (res === 'network-error') throw new TypeError('Failed to fetch');
      return new Response(res.status === 204 ? null : JSON.stringify(res.body ?? {}), { status: res.status, headers: { 'Content-Type': 'application/json' } });
    }),
  );
  return calls;
}

export const header = (init: RequestInit, name: string) => (init.headers as Record<string, string> | undefined)?.[name];

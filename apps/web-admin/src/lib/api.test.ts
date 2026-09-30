import { afterEach, describe, expect, it, vi } from 'vitest';
import { mockApi } from '../test/utils';
import { api, ApiError } from './api';

afterEach(() => {
  vi.unstubAllGlobals();
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('api client', () => {
  it('parses the server error shape into ApiError', async () => {
    mockApi({ 'GET /api/super/tenants': () => ({ status: 402, body: { error: { code: 'SUBSCRIPTION_INACTIVE', message: 'Obuna tugagan', requestId: 'r1' } } }) });

    const err = await api('/super/tenants').catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).code).toBe('SUBSCRIPTION_INACTIVE');
    expect((err as ApiError).message).toBe('Obuna tugagan');
    expect((err as ApiError).requestId).toBe('r1');
  });

  it('exposes validation field errors', async () => {
    mockApi({ 'POST /api/super/tenants': () => ({ status: 422, body: { error: { code: 'VALIDATION_FAILED', message: 'x', fields: { name: ['Nomi majburiy'] } } } }) });
    document.cookie = 'XSRF-TOKEN=abc';

    const err = (await api('/super/tenants', { method: 'POST', body: {} }).catch((e: unknown) => e)) as ApiError;
    expect(err.fieldError('name')).toBe('Nomi majburiy');
  });

  it('sends CSRF and an Idempotency-Key on state-changing requests only', async () => {
    const { calls } = mockApi({
      'GET /api/auth/csrf': () => ({ status: 204 }),
      'POST /api/auth/logout': () => ({ status: 204 }),
      'GET /api/me': () => ({ status: 200, body: {} }),
    });
    document.cookie = 'XSRF-TOKEN=tok%3D1';

    await api('/me');
    await api('/auth/logout', { method: 'POST' });

    const get = calls.find((c) => c.method === 'GET')!;
    const post = calls.find((c) => c.method === 'POST')!;
    const h = (c: typeof post) => c.init.headers as Record<string, string>;
    expect(h(get)['Idempotency-Key']).toBeUndefined();
    expect(h(post)['X-XSRF-TOKEN']).toBe('tok=1');
    expect(h(post)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('maps network failures to a friendly Uzbek message', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('Failed to fetch'); }));

    const err = (await api('/me').catch((e: unknown) => e)) as ApiError;
    expect(err.code).toBe('NETWORK_ERROR');
    expect(err.message).toContain("Aloqa yo'q");
  });
});

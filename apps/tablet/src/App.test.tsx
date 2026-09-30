import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { App } from './App';
import { setToken } from './lib/api';
import { KEYS, storage } from './lib/storage';
import type { PhotoDeps } from './screens/PhotoScreen';
import { bootstrap, ID, session, table } from './test/fixtures';
import { header, mockApi } from './test/utils';

beforeAll(() => {
  // jsdom has no media pipeline: pretend the video has frames.
  Object.defineProperty(HTMLMediaElement.prototype, 'readyState', { configurable: true, get: () => 4 });
  HTMLMediaElement.prototype.play = () => Promise.resolve();
  vi.stubGlobal('speechSynthesis', undefined);
});

beforeEach(async () => {
  setToken(null);
  await storage.del(KEYS.token);
  await storage.del(KEYS.pairing);
  await storage.del(KEYS.bootstrap);
});
afterEach(() => vi.unstubAllGlobals());

const fakeStream = () => ({ getTracks: () => [{ stop: vi.fn() }] }) as unknown as MediaStream;
const face = { x: 0.35, y: 0.25, width: 0.3, height: 0.4, score: 0.95 };

function photoDeps(overrides: Partial<PhotoDeps> = {}): PhotoDeps {
  return {
    openCamera: async () => fakeStream(),
    loadDetector: async () => ({ detect: () => [face], close: () => undefined }),
    capture: async () => new Blob(['jpeg'], { type: 'image/jpeg' }),
    ...overrides,
  };
}

describe('pairing', () => {
  it('shows the pairing code, waits for the admin and stores the token', async () => {
    let polls = 0;
    const calls = mockApi({
      'POST /api/tablet/register': () => ({
        status: 201,
        body: { tabletCode: 'TABLET-ABC123', pairingCode: '482913', pairingExpiresAt: new Date(Date.now() + 900_000).toISOString(), pollToken: 'p'.repeat(40), serverTime: new Date().toISOString() },
      }),
      'GET /api/tablet/pairing-status': () => ({ status: 200, body: polls++ === 0 ? { status: 'WAITING', serverTime: new Date().toISOString() } : { status: 'PAIRED', token: 't'.repeat(43), serverTime: new Date().toISOString() } }),
      'GET /api/tablet/bootstrap': () => ({ status: 200, body: bootstrap([table(1)]) }),
      'GET /api/tablet/tables': () => ({ status: 200, body: { serverTime: new Date().toISOString(), isOpenNow: true, tables: [table(1)] } }),
      'POST /api/tablet/heartbeat': () => ({ status: 204 }),
    });
    render(<App />);

    expect(await screen.findByLabelText('Ulash kodi')).toHaveTextContent('482913');
    expect(screen.getByText('TABLET-ABC123')).toBeInTheDocument();

    expect(await screen.findByText('Stolni tanlang', {}, { timeout: 5000 })).toBeInTheDocument();
    expect(await storage.get(KEYS.token)).toBe('t'.repeat(43));
    const poll = calls.find((c) => c.path === '/api/tablet/pairing-status')!;
    expect(header(poll.init, 'Authorization')).toBe(`PollToken ${'p'.repeat(40)}`);
    const boot = calls.find((c) => c.path === '/api/tablet/bootstrap')!;
    expect(header(boot.init, 'Authorization')).toBe(`Bearer ${'t'.repeat(43)}`);
  });

  it('goes back to pairing when the token is revoked', async () => {
    await storage.set(KEYS.token, 'old-token');
    mockApi({
      'GET /api/tablet/bootstrap': () => ({ status: 401, body: { error: { code: 'UNAUTHENTICATED', message: 'Tizimga kiring.' } } }),
      'POST /api/tablet/heartbeat': () => ({ status: 401, body: {} }),
      'POST /api/tablet/register': () => ({
        status: 201,
        body: { tabletCode: 'TABLET-NEW001', pairingCode: '111222', pairingExpiresAt: new Date(Date.now() + 900_000).toISOString(), pollToken: 'q'.repeat(40), serverTime: new Date().toISOString() },
      }),
      'GET /api/tablet/pairing-status': () => ({ status: 200, body: { status: 'WAITING', serverTime: new Date().toISOString() } }),
    });
    render(<App />);

    expect(await screen.findByLabelText('Ulash kodi')).toHaveTextContent('111222');
    expect(await storage.get(KEYS.token)).toBeUndefined();
  });
});

describe('customer flow', () => {
  beforeEach(async () => {
    await storage.set(KEYS.token, 'tok');
  });

  it('table → duration → confirm → one photo → start → countdown', async () => {
    const endAt = new Date(Date.now() + 3_600_000).toISOString();
    let shows = 0;
    const calls = mockApi({
      'GET /api/tablet/bootstrap': () => ({ status: 200, body: bootstrap([table(1), table(2, { status: 'BUSY', session: { id: ID(77), status: 'ACTIVE', startAt: null, endAt } })]) }),
      'GET /api/tablet/tables': () => ({ status: 200, body: { serverTime: new Date().toISOString(), isOpenNow: true, tables: [table(1)] } }),
      'POST /api/tablet/heartbeat': () => ({ status: 204 }),
      'POST /api/tablet/sessions/prepare': () => ({ status: 201, body: session('RESERVED') }),
      [`POST /api/tablet/sessions/${ID(500)}/photo`]: () => ({ status: 200, body: session('RESERVED', { hasPhoto: true }) }),
      [`POST /api/tablet/sessions/${ID(500)}/start`]: () => ({ status: 200, body: session('STARTING', { hasPhoto: true }) }),
      [`GET /api/tablet/sessions/${ID(500)}`]: () => ({ status: 200, body: shows++ === 0 ? session('STARTING') : session('ACTIVE', { startAt: new Date().toISOString(), endAt }) }),
    });
    render(<App photoDeps={photoDeps()} />);

    // Busy tables cannot be chosen.
    const busy = await screen.findByRole('button', { name: /2-stol/ });
    expect(busy).toBeDisabled();

    await userEvent.click(screen.getByRole('button', { name: /1-stol/ }));
    await userEvent.click(await screen.findByRole('button', { name: /1 soat/ }));
    expect(screen.getByText('Tasdiqlang')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Davom etish' }));

    expect(await screen.findByText('Iltimos, kameraga qarang')).toBeInTheDocument();
    expect(await screen.findByText("O'yin boshlandi!", {}, { timeout: 8000 })).toBeInTheDocument();

    const prepare = calls.find((c) => c.path.endsWith('/prepare'))!;
    expect(JSON.parse(String(prepare.init.body))).toEqual({ tableId: ID(1), durationMinutes: 60 });
    const photo = calls.filter((c) => c.path.endsWith('/photo'));
    expect(photo).toHaveLength(1); // exactly one photo
    expect(photo[0]!.init.body).toBeInstanceOf(FormData);
    for (const c of calls.filter((c) => c.method === 'POST' && c.path.includes('/sessions/'))) {
      expect(header(c.init, 'Idempotency-Key')).toMatch(/^[0-9a-f-]{36}$/);
    }
  }, 15_000);

  it('retries a failed prepare with the same idempotency key (never two sessions)', async () => {
    let attempts = 0;
    const calls = mockApi({
      'GET /api/tablet/bootstrap': () => ({ status: 200, body: bootstrap([table(1)]) }),
      'GET /api/tablet/tables': () => ({ status: 200, body: { serverTime: new Date().toISOString(), isOpenNow: true, tables: [table(1)] } }),
      'POST /api/tablet/heartbeat': () => ({ status: 204 }),
      'POST /api/tablet/sessions/prepare': () => (attempts++ === 0 ? 'network-error' : { status: 201, body: session('RESERVED') }),
    });
    render(<App photoDeps={photoDeps({ loadDetector: () => new Promise(() => undefined) })} />);

    await userEvent.click(await screen.findByRole('button', { name: /1-stol/ }));
    await userEvent.click(await screen.findByRole('button', { name: /30 daqiqa/ }));
    await userEvent.click(screen.getByRole('button', { name: 'Davom etish' }));
    expect(await screen.findByRole('alert')).toHaveTextContent("Aloqa yo'q");
    await userEvent.click(screen.getByRole('button', { name: 'Davom etish' }));
    expect(await screen.findByText('Iltimos, kameraga qarang')).toBeInTheDocument();

    const keys = calls.filter((c) => c.path.endsWith('/prepare')).map((c) => header(c.init, 'Idempotency-Key'));
    expect(keys).toHaveLength(2);
    expect(keys[0]).toBe(keys[1]);
  });

  it('shows the manual button only when face detection cannot load, and cancelling frees the table', async () => {
    const calls = mockApi({
      'GET /api/tablet/bootstrap': () => ({ status: 200, body: bootstrap([table(1)]) }),
      'GET /api/tablet/tables': () => ({ status: 200, body: { serverTime: new Date().toISOString(), isOpenNow: true, tables: [table(1)] } }),
      'POST /api/tablet/heartbeat': () => ({ status: 204 }),
      'POST /api/tablet/sessions/prepare': () => ({ status: 201, body: session('RESERVED') }),
      [`POST /api/tablet/sessions/${ID(500)}/cancel`]: () => ({ status: 200, body: session('CANCELLED') }),
    });
    render(<App photoDeps={photoDeps({ loadDetector: () => Promise.reject(new Error('no wasm')) })} />);

    await userEvent.click(await screen.findByRole('button', { name: /1-stol/ }));
    await userEvent.click(await screen.findByRole('button', { name: /30 daqiqa/ }));
    await userEvent.click(screen.getByRole('button', { name: 'Davom etish' }));
    expect(await screen.findByRole('button', { name: 'Suratga olish' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Suratsiz davom etish' })).not.toBeInTheDocument(); // photo required

    await userEvent.click(screen.getByRole('button', { name: 'Bekor qilish' }));
    await waitFor(() => expect(calls.some((c) => c.path.endsWith('/cancel'))).toBe(true));
    expect(await screen.findByText('Stolni tanlang')).toBeInTheDocument();
  });
});

describe('offline', () => {
  it('shows cached tables read-only with the connection message', async () => {
    await storage.set(KEYS.token, 'tok');
    await storage.set(KEYS.bootstrap, bootstrap([table(1)]));
    mockApi({
      'GET /api/tablet/bootstrap': () => 'network-error',
      'GET /api/tablet/tables': () => 'network-error',
      'POST /api/tablet/heartbeat': () => 'network-error',
    });
    render(<App />);

    expect(await screen.findByText("Aloqa yo'q. Iltimos, kuting. Yangi o'yin boshlab bo'lmaydi.")).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /1-stol/ })).toBeDisabled();
  });

  it('shows the closed screen outside working hours', async () => {
    await storage.set(KEYS.token, 'tok');
    const b = bootstrap([table(1)]);
    b.branch.isOpenNow = false;
    mockApi({
      'GET /api/tablet/bootstrap': () => ({ status: 200, body: b }),
      'GET /api/tablet/tables': () => ({ status: 200, body: { serverTime: new Date().toISOString(), isOpenNow: false, tables: [table(1)] } }),
      'POST /api/tablet/heartbeat': () => ({ status: 204 }),
    });
    render(<App />);
    expect(await screen.findByText('Hozir ish vaqti emas')).toBeInTheDocument();
  });
});

import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { routes } from '../../App';
import { mockApi, renderRoutes } from '../../test/utils';

afterEach(() => vi.unstubAllGlobals());

const me = {
  user: { id: 'U', name: 'Op', login: 'op', role: 'CLIENT_OPERATOR', isActive: true, lastLoginAt: null },
  permissions: ['sessions.view', 'sessions.stop', 'sessions.mark_payment', 'tables.view'],
  tenant: { id: 'T', name: 'Ali Billiard', subscription: { status: 'ACTIVE', expiresAt: '2026-12-01T00:00:00Z', daysLeft: 60 } },
};
const session = {
  id: 'S1', status: 'ACTIVE', effectiveStatus: 'ACTIVE', branch: { id: 'B', name: 'Markaz' }, table: { id: 'TB', number: 1, name: '1-stol' },
  device: { code: 'ESP32-A1B2C3', online: true }, durationMinutes: 60, startAt: '2026-10-05T05:00:00Z', endAt: '2026-10-05T06:00:00Z',
  endedAt: null, endedEarly: false, pricePerHour: 20000, amount: 20000, paymentStatus: 'UNPAID', paymentMarkedAt: null, failureReason: null,
  photo: null, events: [{ from: null, to: 'RESERVED', actorType: 'TABLET', reason: null, at: '2026-10-05T04:59:00Z' }], createdAt: '2026-10-05T04:59:00Z',
};

describe('session detail', () => {
  it('lets staff mark payment manually', async () => {
    let paid = false;
    const { calls } = mockApi({
      'GET /api/me': () => ({ status: 200, body: me }),
      'GET /api/admin/sessions/S1': () => ({ status: 200, body: { data: { ...session, paymentStatus: paid ? 'PAID' : 'UNPAID' } } }),
      'GET /api/auth/csrf': () => ({ status: 204 }),
      'POST /api/admin/sessions/S1/payment': () => { paid = true; return { status: 200, body: { data: session } }; },
    });
    document.cookie = 'XSRF-TOKEN=t';
    renderRoutes(routes, '/client/sessions/S1');

    expect(await screen.findByText('1-stol · Markaz')).toBeInTheDocument();
    expect(screen.getByText(/05\.10\.2026 10:00 → 05\.10\.2026 11:00/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: "To'langan" }));

    const post = calls.find((c) => c.method === 'POST' && c.url.includes('/payment'))!;
    expect(JSON.parse(String(post.init.body))).toEqual({ status: 'PAID' });
    expect(await screen.findAllByText("To'langan")).not.toHaveLength(0);
  });
});

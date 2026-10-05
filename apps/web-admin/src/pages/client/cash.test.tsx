import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { routes } from '../../App';
import { mockApi, renderRoutes } from '../../test/utils';

afterEach(() => vi.unstubAllGlobals());

const manager = {
  user: { id: 'U', name: 'Vali', login: 'vali', role: 'CLIENT_MANAGER', isActive: true, lastLoginAt: null, twoFactorEnabled: false },
  permissions: ['reports.view', 'cash.manage'],
  tenant: { id: 'T', name: 'Dunyo', subscription: { status: 'ACTIVE', expiresAt: '2026-12-01T00:00:00Z', daysLeft: 50 } },
};
const overview = {
  boxes: [{
    branch: { id: 'B1', name: 'Dun1', paymentMode: 'BILL_ACCEPTOR' },
    device: { id: 'D1', code: 'ESP32-CA5H01', online: true, lastSeenAt: '2026-10-07T18:00:00Z', accepting: false, queued: 0 },
    uncollected: { amount: 26000, count: 3 }, today: { amount: 26000, count: 3 }, unassigned: { amount: 6000, count: 2 },
  }],
  notes: [
    { id: 'N1', nominal: 5000, status: 'UNASSIGNED', receivedAt: '2026-10-07T18:01:00Z', branch: 'Dun1', table: '1-stol', sessionId: 'S1', resolveComment: null },
    { id: 'N2', nominal: 20000, status: 'CREDITED', receivedAt: '2026-10-07T18:00:00Z', branch: 'Dun1', table: '1-stol', sessionId: 'S1', resolveComment: null },
  ],
  collections: [{ id: 'C1', branch: 'Dun1', deviceCode: 'ESP32-CA5H01', expected: 50000, counted: 49000, notesCount: 4, collectedBy: 'Vali', comment: null, createdAt: '2026-10-06T22:00:00Z' }],
};

describe('cash page', () => {
  it('shows the box totals, records a collection and resolves an unassigned bill', async () => {
    document.cookie = 'XSRF-TOKEN=t';
    const api = mockApi({
      'GET /api/me': () => ({ status: 200, body: manager }),
      'GET /api/admin/notifications': () => ({ status: 200, body: { data: [], unread: 0, meta: { page: 1, lastPage: 1, total: 0 } } }),
      'GET /api/admin/cash': () => ({ status: 200, body: overview }),
      'POST /api/admin/cash/collections': () => ({ status: 201, body: { data: { id: 'C2', expected: 26000, counted: 26000, notesCount: 3 } } }),
      'POST /api/admin/cash/notes/N1/resolve': () => ({ status: 200, body: { data: { id: 'N1', status: 'RESOLVED', resolveComment: 'vaqt berildi' } } }),
    });
    vi.stubGlobal('confirm', () => true);
    vi.stubGlobal('prompt', () => "Qo'shimcha vaqt berildi");
    renderRoutes(routes, '/client/cash');

    expect(await screen.findByText('Dun1')).toBeInTheDocument();
    expect(screen.getAllByText("26 000 so'm").length).toBeGreaterThan(0);
    expect(screen.getByText("6 000 so'm")).toBeInTheDocument();
    expect(screen.getByText(/Farq: -1 000 so'm/)).toBeInTheDocument();

    await userEvent.type(screen.getByLabelText("Sanalgan summa (so'm)"), '26000');
    await userEvent.click(screen.getByRole('button', { name: 'Pul olindi' }));
    const collect = api.calls.find((c) => c.url.endsWith('/api/admin/cash/collections'))!;
    expect(JSON.parse(String(collect.init.body))).toEqual({ deviceId: 'D1', countedAmount: 26000 });

    await userEvent.click(screen.getByRole('button', { name: 'Hal qilish' }));
    const resolve = api.calls.find((c) => c.url.endsWith('/N1/resolve'))!;
    expect(JSON.parse(String(resolve.init.body))).toEqual({ comment: "Qo'shimcha vaqt berildi" });
  });
});

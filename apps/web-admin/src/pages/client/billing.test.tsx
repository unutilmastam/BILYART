import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { routes } from '../../App';
import { mockApi, renderRoutes } from '../../test/utils';

afterEach(() => vi.unstubAllGlobals());

const limits = { branchLimit: 3, tableLimit: null, deviceLimit: null, userLimit: null };
const owner = {
  user: { id: 'U', name: 'Ali', login: 'ali', role: 'CLIENT_OWNER', isActive: true, lastLoginAt: null, twoFactorEnabled: false },
  permissions: ['billing.manage', 'branches.manage'],
  tenant: { id: 'T', name: 'Dunyo', subscription: { status: 'EXPIRED', expiresAt: '2026-09-28T00:00:00Z', daysLeft: 0 } },
};
const request = (status: string, extra = {}) => ({
  id: 'P1', status, months: 3, branchCount: 2, pricePerBranch: 100000, amount: 600000, note: null, rejectReason: null,
  createdAt: '2026-10-01T08:00:00Z', reviewedAt: null, ...extra,
});
const subscription = (pending: unknown) => ({
  status: 'EXPIRED', expiresAt: '2026-09-28T00:00:00Z', daysLeft: 0, limits, supportContact: '+998 90 000 00 00', paymentInstructions: 'Karta: 8600 …',
  billing: { pricePerBranch: 100000, branchCount: 2, monthlyAmount: 200000, monthOptions: [1, 3, 6, 12], pending },
});
const notifications = { status: 200, body: { data: [], unread: 0, meta: { page: 1, lastPage: 1, total: 0 } } };

describe('subscription payment', () => {
  it('lets the owner pick a period, attach a receipt and send it', async () => {
    document.cookie = 'XSRF-TOKEN=t';
    let pending: unknown = null;
    const api = mockApi({
      'GET /api/me': () => ({ status: 200, body: owner }),
      'GET /api/admin/notifications': () => notifications,
      'GET /api/admin/subscription': () => ({ status: 200, body: subscription(pending) }),
      'GET /api/admin/payment-requests': () => ({ status: 200, body: { data: pending ? [pending] : [] } }),
      'POST /api/admin/payment-requests': () => {
        pending = request('PENDING');
        return { status: 201, body: { data: pending } };
      },
    });
    renderRoutes(routes, '/client/subscription');

    expect(await screen.findByText(/2 ta filial × 100 000 so'm/)).toBeInTheDocument();
    expect(screen.getByText('Karta: 8600 …')).toBeInTheDocument();
    const send = screen.getByRole('button', { name: "To'lov qildim — yuborish" });
    expect(send).toBeDisabled(); // no receipt yet

    await userEvent.click(screen.getByLabelText(/3 oy/));
    expect(screen.getAllByText("600 000 so'm").length).toBeGreaterThan(0);
    await userEvent.upload(screen.getByLabelText(/To'lov cheki/), new File([new Uint8Array([0x89, 0x50])], 'chek.png', { type: 'image/png' }));
    await userEvent.click(send);

    const call = api.calls.find((c) => c.method === 'POST' && c.url.endsWith('/api/admin/payment-requests'))!;
    const form = call.init.body as FormData;
    expect(form.get('months')).toBe('3');
    expect(form.get('receipt')).toBeInstanceOf(File);
    expect((call.init.headers as Record<string, string>)['Idempotency-Key']).toBeTruthy();
    expect(await screen.findByText(/Administrator tekshirmoqda/)).toBeInTheDocument();
    expect(await screen.findByText('Tekshirilmoqda')).toBeInTheDocument(); // history badge
  });

  it('shows the pay button on the home page of an expired client', async () => {
    mockApi({
      'GET /api/me': () => ({ status: 200, body: owner }),
      'GET /api/admin/notifications': () => notifications,
      'GET /api/admin/subscription': () => ({ status: 200, body: subscription(null) }),
    });
    renderRoutes(routes, '/client');
    expect(await screen.findByRole('link', { name: "Obunani to'lash" })).toHaveAttribute('href', '/client/subscription');
  });

  it('managers see the status but cannot pay', async () => {
    mockApi({
      'GET /api/me': () => ({ status: 200, body: { ...owner, permissions: ['reports.view'] } }),
      'GET /api/admin/notifications': () => notifications,
      'GET /api/admin/subscription': () => ({ status: 200, body: subscription(null) }),
    });
    renderRoutes(routes, '/client/subscription');
    expect(await screen.findByText('Obuna holati')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /yuborish/ })).not.toBeInTheDocument();
  });
});

describe('super admin payment requests', () => {
  const superMe = { user: { id: 'S', name: 'Root', login: 'root', role: 'SUPER_ADMIN', isActive: true, lastLoginAt: null, twoFactorEnabled: true }, permissions: ['platform.payments'], tenant: null };

  it('approves with a corrected amount and rejects with a reason', async () => {
    document.cookie = 'XSRF-TOKEN=t';
    const api = mockApi({
      'GET /api/me': () => ({ status: 200, body: superMe }),
      'GET /api/super/notifications': () => notifications,
      'GET /api/super/payment-requests': () => ({ status: 200, body: { data: [request('PENDING', { tenant: { id: 'T', name: 'Dunyo' }, note: 'Click' }), request('PENDING', { id: 'P2', tenant: { id: 'T2', name: 'Shox' } })] } }),
      'POST /api/super/payment-requests/P1/approve': () => ({ status: 200, body: { data: request('APPROVED') } }),
      'POST /api/super/payment-requests/P2/reject': () => ({ status: 200, body: { data: request('REJECTED') } }),
    });
    vi.stubGlobal('confirm', () => true);
    renderRoutes(routes, '/super/payment-requests');

    expect(await screen.findByText('Dunyo')).toBeInTheDocument();
    expect(api.calls.some((c) => c.url.includes('/api/super/payment-requests?status=PENDING'))).toBe(true);

    await userEvent.click(screen.getAllByRole('button', { name: 'Tekshirish' })[0]!);
    expect(screen.getByRole('button', { name: "Chekni ko'rish" })).toBeInTheDocument();
    const amount = screen.getByLabelText("Tushgan summa (so'm)");
    await userEvent.clear(amount);
    await userEvent.type(amount, '550000');
    await userEvent.selectOptions(screen.getByLabelText("To'lov usuli"), 'BANK_TRANSFER');
    await userEvent.click(screen.getByRole('button', { name: 'Tasdiqlash' }));
    const approve = api.calls.find((c) => c.url.endsWith('/P1/approve'))!;
    expect(JSON.parse(String(approve.init.body))).toEqual({ amount: 550000, method: 'BANK_TRANSFER' });

    await userEvent.click(screen.getByRole('button', { name: 'Tekshirish' })); // the second request (the first one is open)
    await userEvent.click(screen.getByRole('button', { name: 'Rad etish…' }));
    const reject = screen.getByRole('button', { name: 'Rad etish' });
    expect(reject).toBeDisabled();
    await userEvent.type(screen.getByLabelText(/Rad etish sababi/), 'Pul tushmagan');
    await userEvent.click(reject);
    expect(JSON.parse(String(api.calls.find((c) => c.url.endsWith('/P2/reject'))!.init.body))).toEqual({ reason: 'Pul tushmagan' });
  });
});

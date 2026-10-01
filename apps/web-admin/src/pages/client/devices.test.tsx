import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { routes } from '../../App';
import { mockApi, renderRoutes } from '../../test/utils';

afterEach(() => vi.unstubAllGlobals());
beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=t';
});

const me = {
  user: { id: 'U', name: 'Ali', login: 'ali', role: 'CLIENT_OWNER', isActive: true, lastLoginAt: null },
  permissions: ['tables.view', 'tables.manage', 'devices.manage'],
  tenant: { id: 'T', name: 'Ali Billiard', subscription: { status: 'ACTIVE', expiresAt: '2026-12-01T00:00:00Z', daysLeft: 60 } },
};
const branch = { id: 'B'.repeat(26), name: 'Markaz', isActive: true };
const table = (n: number, device: unknown = null) => ({ id: `T${n}`.padEnd(26, '0'), branchId: branch.id, number: n, name: `${n}-stol`, isActive: true, pricingPlan: null, device });
const esp = {
  id: 'D'.repeat(26), code: 'ESP32-B2D3E4', status: 'PAIRED', online: true, lastSeenAt: '2026-10-01T10:00:00Z', firmwareVersion: '1.1.0', rssi: -60,
  channelCount: 4, branch: { id: branch.id, name: 'Markaz' }, pairedAt: null,
  channels: [
    { channel: 1, table: { id: table(1).id, number: 1, name: '1-stol' }, state: 'ON' },
    { channel: 2, table: null, state: 'OFF' },
    { channel: 3, table: { id: table(3).id, number: 3, name: '3-stol' }, state: 'OFF' },
    { channel: 4, table: null, state: 'OFF' },
  ],
};
const base = {
  'GET /api/me': () => ({ status: 200, body: me }),
  'GET /api/admin/subscription': () => ({ status: 200, body: { status: 'ACTIVE', expiresAt: null, daysLeft: 60, limits: {}, supportContact: '', paymentInstructions: '' } }),
  'GET /api/admin/notifications': () => ({ status: 200, body: { data: [], unread: 0, meta: { page: 1, lastPage: 1, total: 0 } } }),
  'GET /api/admin/branches': () => ({ status: 200, body: { data: [branch] } }),
  'GET /api/admin/devices': () => ({ status: 200, body: { data: [esp] } }),
  'GET /api/admin/tablets': () => ({ status: 200, body: { data: [] } }),
  'GET /api/auth/csrf': () => ({ status: 204 }),
};

describe('one ESP32 per branch', () => {
  it('pairs a device to a branch and lists its channels with tables and lamp states', async () => {
    const api = mockApi({ ...base, 'POST /api/admin/devices/pair': () => ({ status: 201, body: { data: esp } }) });
    renderRoutes(routes, '/client/devices');

    const channels = await screen.findByRole('list', { name: 'Kanallar' });
    expect(within(channels).getAllByRole('listitem')).toHaveLength(4);
    expect(within(channels).getByText('1-stol')).toBeInTheDocument();
    expect(within(channels).getByText('yoniq')).toBeInTheDocument();
    expect(within(channels).getAllByText("bo'sh")).toHaveLength(2);

    await userEvent.type(screen.getAllByLabelText('Ulash kodi (6 raqam)')[0]!, '123456');
    await userEvent.click(screen.getAllByRole('button', { name: 'Ulash' })[0]!);
    await vi.waitFor(() => expect(api.calls.some((c) => c.method === 'POST' && c.url.endsWith('/admin/devices/pair'))).toBe(true));
    const call = api.calls.find((c) => c.method === 'POST' && c.url.endsWith('/admin/devices/pair'))!;
    expect(JSON.parse(String(call.init.body))).toEqual({ code: '123456', branchId: branch.id });
  });

  it('wires a table to a free relay channel from the tables page', async () => {
    const api = mockApi({
      ...base,
      'GET /api/admin/tables': () => ({ status: 200, body: { data: [table(1, { id: esp.id, code: esp.code, channel: 1, online: true, lastSeenAt: null }), table(2)] } }),
      'PATCH /api/admin/tables/T2000000000000000000000000': () => ({ status: 200, body: { data: table(2) } }),
    });
    renderRoutes(routes, '/client/tables');

    const selects = await screen.findAllByLabelText('Chiroq (qurilma va kanal)');
    // Table 2 may use channels 2 and 4 only (1 and 3 are taken by other tables).
    const options = within(selects[1]!).getAllByRole('option').map((o) => o.textContent);
    expect(options).toEqual(['Ulanmagan', 'ESP32-B2D3E4 · 2-kanal', 'ESP32-B2D3E4 · 4-kanal']);
    await userEvent.selectOptions(selects[1]!, `${esp.id}:4`);

    await vi.waitFor(() => expect(api.calls.some((c) => c.method === 'PATCH')).toBe(true));
    const call = api.calls.find((c) => c.method === 'PATCH')!;
    expect(JSON.parse(String(call.init.body))).toEqual({ deviceId: esp.id, deviceChannel: 4 });
  });
});

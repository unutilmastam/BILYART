import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { routes } from '../../App';
import { mockApi, renderRoutes } from '../../test/utils';
import { parseDurations } from './PricingPage';

afterEach(() => vi.unstubAllGlobals());

const me = (role: string, permissions: string[]) => ({
  user: { id: 'U', name: 'Ali', login: 'ali', role, isActive: true, lastLoginAt: null },
  permissions,
  tenant: { id: 'T', name: 'Ali Billiard', subscription: { status: 'ACTIVE', expiresAt: '2026-12-01T00:00:00Z', daysLeft: 60 } },
});

describe('client area', () => {
  it('parses allowed durations strictly', () => {
    expect(parseDurations('60, 30 90;120')).toEqual([30, 60, 90, 120]);
    expect(parseDurations('30, abc')).toBeNull();
    expect(parseDurations('0')).toBeNull();
    expect(parseDurations('')).toBeNull();
  });

  it('shows navigation only for permitted sections', async () => {
    mockApi({
      'GET /api/me': () => ({ status: 200, body: me('CLIENT_OPERATOR', ['tables.view', 'sessions.view']) }),
      'GET /api/admin/subscription': () => ({ status: 200, body: { status: 'ACTIVE', expiresAt: null, daysLeft: 60, limits: {}, supportContact: '', paymentInstructions: '' } }),
    });
    renderRoutes(routes, '/client');

    expect(await screen.findByRole('link', { name: 'Stollar' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Xodimlar' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Narxlar' })).not.toBeInTheDocument();
  });

  it('shows the license limit message when a branch cannot be created', async () => {
    mockApi({
      'GET /api/me': () => ({ status: 200, body: me('CLIENT_OWNER', ['branches.manage']) }),
      'GET /api/admin/branches': () => ({ status: 200, body: { data: [] } }),
      'GET /api/auth/csrf': () => ({ status: 204 }),
      'POST /api/admin/branches': () => ({ status: 422, body: { error: { code: 'LIMIT_REACHED', message: 'Litsenziya limitiga yetdingiz (2).' } } }),
    });
    document.cookie = 'XSRF-TOKEN=t';
    renderRoutes(routes, '/client/branches');

    await userEvent.type(await screen.findByLabelText('Filial nomi'), 'Uchinchi');
    await userEvent.click(screen.getByRole('button', { name: "Filial qo'shish" }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Litsenziya limitiga yetdingiz (2).');
  });
});

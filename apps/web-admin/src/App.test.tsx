import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { routes } from './App';
import { mockApi, renderRoutes } from './test/utils';

afterEach(() => vi.unstubAllGlobals());

const superMe = { user: { id: 'U1', name: 'Boss', login: 'boss', role: 'SUPER_ADMIN', isActive: true, lastLoginAt: null }, permissions: [], tenant: null };
const ownerMe = {
  user: { id: 'U2', name: 'Ali', login: 'ali', role: 'CLIENT_OWNER', isActive: true, lastLoginAt: null },
  permissions: [],
  tenant: { id: 'T1', name: 'Ali Billiard', subscription: { status: 'EXPIRED', expiresAt: null, daysLeft: 0 } },
};

describe('routing and auth', () => {
  it('sends guests to the login page', async () => {
    mockApi({ 'GET /api/me': () => ({ status: 401, body: { error: { code: 'UNAUTHENTICATED', message: 'Tizimga kiring.' } } }) });
    renderRoutes(routes, '/super/clients');
    expect(await screen.findByRole('button', { name: 'Kirish' })).toBeInTheDocument();
  });

  it('logs a super admin in and shows the platform dashboard', async () => {
    let loggedIn = false;
    mockApi({
      'GET /api/me': () => (loggedIn ? { status: 200, body: superMe } : { status: 401, body: { error: { code: 'UNAUTHENTICATED', message: 'x' } } }),
      'GET /api/auth/csrf': () => ({ status: 204 }),
      'POST /api/auth/login': () => { loggedIn = true; return { status: 200, body: superMe }; },
      'GET /api/super/dashboard': () => ({
        status: 200,
        body: {
          tenants: { total: 3, active: 2, expiringSoon: 1, expired: 0, suspended: 0, deactivated: 0 },
          revenue: { currency: 'UZS', thisMonth: 800000, total: 1500000 },
          devices: { paired: 4, online: 3 },
          recentAudit: [{ id: 1, action: 'tenant.created', actorType: 'USER', actorName: 'Boss', entityType: 'Tenant', entityId: 'x', metadata: null, ip: null, tenant: 'Ali Billiard', createdAt: '2026-10-01T10:00:00Z' }],
        },
      }),
    });
    document.cookie = 'XSRF-TOKEN=t';
    renderRoutes(routes, '/login');

    await userEvent.type(await screen.findByLabelText('Login'), 'boss');
    await userEvent.type(screen.getByLabelText('Parol'), 'secret-password-1');
    await userEvent.click(screen.getByRole('button', { name: 'Kirish' }));

    expect(await screen.findByText(/^800.000.so'm$/)).toBeInTheDocument();
    expect(screen.getByText('3 / 4')).toBeInTheDocument();
    expect(screen.getByText('tenant.created')).toBeInTheDocument();
  });

  it('shows the server message on wrong credentials', async () => {
    mockApi({
      'GET /api/me': () => ({ status: 401, body: { error: { code: 'UNAUTHENTICATED', message: 'x' } } }),
      'GET /api/auth/csrf': () => ({ status: 204 }),
      'POST /api/auth/login': () => ({ status: 401, body: { error: { code: 'INVALID_CREDENTIALS', message: "Login yoki parol noto'g'ri." } } }),
    });
    document.cookie = 'XSRF-TOKEN=t';
    renderRoutes(routes, '/login');

    await userEvent.type(await screen.findByLabelText('Login'), 'x');
    await userEvent.type(screen.getByLabelText('Parol'), 'y');
    await userEvent.click(screen.getByRole('button', { name: 'Kirish' }));

    expect(await screen.findByRole('alert')).toHaveTextContent("Login yoki parol noto'g'ri.");
  });

  it('keeps client users out of the super area and shows inactive subscription info', async () => {
    mockApi({
      'GET /api/me': () => ({ status: 200, body: ownerMe }),
      'GET /api/admin/subscription': () => ({
        status: 200,
        body: { status: 'EXPIRED', expiresAt: null, daysLeft: 0, limits: { branchLimit: 1, tableLimit: null, deviceLimit: null, userLimit: null }, supportContact: '+998 90 000', paymentInstructions: 'Karta: 8600' },
      }),
    });
    const { router } = renderRoutes(routes, '/super');

    await waitFor(() => expect(router.state.location.pathname).toBe('/client'));
    expect(await screen.findByText('Karta: 8600')).toBeInTheDocument();
    expect(screen.getByText(/\+998 90 000/)).toBeInTheDocument();
  });
});

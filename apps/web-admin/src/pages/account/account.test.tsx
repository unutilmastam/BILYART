import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { routes } from '../../App';
import { mockApi, renderRoutes } from '../../test/utils';

afterEach(() => vi.unstubAllGlobals());
beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=t';
});

const me = (twoFactorEnabled: boolean) => ({
  user: { id: 'U', name: 'Root', login: 'root', role: 'SUPER_ADMIN', isActive: true, lastLoginAt: null, twoFactorEnabled },
  permissions: [],
  tenant: null,
});
const noNotifications = () => ({ status: 200, body: { data: [], unread: 0, meta: { page: 1, lastPage: 1, total: 0 } } });

describe('two-factor login', () => {
  it('asks for the code when the server requires it and sends it on the next attempt', async () => {
    let attempts = 0;
    const api = mockApi({
      'GET /api/me': () => ({ status: 401, body: { error: { code: 'UNAUTHENTICATED', message: 'Tizimga kiring.' } } }),
      'POST /api/auth/login': (_url, init) => {
        attempts++;
        const body = JSON.parse(String(init.body));
        return body.code === '123456'
          ? { status: 200, body: me(true) }
          : { status: 401, body: { error: { code: 'TWO_FACTOR_REQUIRED', message: 'Autentifikator ilovasidagi 6 xonali kodni kiriting.' } } };
      },
      'GET /api/super/notifications': noNotifications,
      'GET /api/super/dashboard': () => ({ status: 200, body: {} }),
    });
    const { router } = renderRoutes(routes, '/login');

    expect(screen.queryByLabelText('Tasdiqlash kodi')).not.toBeInTheDocument();
    await userEvent.type(await screen.findByLabelText('Login'), 'root');
    await userEvent.type(screen.getByLabelText('Parol'), 'secret-password');
    await userEvent.click(screen.getByRole('button', { name: 'Kirish' }));

    await userEvent.type(await screen.findByLabelText('Tasdiqlash kodi'), '123456');
    await userEvent.click(screen.getByRole('button', { name: 'Kirish' }));

    await vi.waitFor(() => expect(router.state.location.pathname).toBe('/super'));
    expect(attempts).toBe(2);
    const last = api.calls.filter((c) => c.url.endsWith('/auth/login')).at(-1)!;
    expect(JSON.parse(String(last.init.body))).toEqual({ login: 'root', password: 'secret-password', code: '123456' });
  });

  it('enables 2FA from the account page and shows the recovery codes once', async () => {
    mockApi({
      'GET /api/me': () => ({ status: 200, body: me(false) }),
      'GET /api/super/notifications': noNotifications,
      'POST /api/me/2fa/setup': () => ({ status: 200, body: { secret: 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', uri: 'otpauth://totp/Bilyart:root?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=Bilyart' } }),
      'POST /api/me/2fa/confirm': () => ({ status: 200, body: { recoveryCodes: ['AAAAA-BBBBB', 'CCCCC-DDDDD'] } }),
    });
    renderRoutes(routes, '/super/account');

    await userEvent.click(await screen.findByRole('button', { name: 'Yoqish' }));
    await userEvent.type(screen.getAllByLabelText('Joriy parol').at(-1)!, 'secret-password');
    await userEvent.click(screen.getByRole('button', { name: 'Keyingi' }));

    expect(await screen.findByRole('link', { name: 'Ilovada ochish' })).toHaveAttribute('href', expect.stringMatching(/^otpauth:\/\/totp\//));
    expect(screen.getByRole('img', { name: 'Ikki bosqichli kirish (2FA)' }).getAttribute('src')).toMatch(/^data:image\//);
    await userEvent.type(screen.getByLabelText('Tasdiqlash kodi'), '654321');
    await userEvent.click(screen.getByRole('button', { name: 'Tasdiqlash' }));

    expect(await screen.findByText('AAAAA-BBBBB')).toBeInTheDocument();
    expect(screen.getByText('CCCCC-DDDDD')).toBeInTheDocument();
  });
});

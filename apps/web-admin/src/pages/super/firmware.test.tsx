import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { routes } from '../../App';
import { mockApi, renderRoutes } from '../../test/utils';

afterEach(() => vi.unstubAllGlobals());

const me = { user: { id: 'U', name: 'Root', login: 'root', role: 'SUPER_ADMIN', isActive: true, lastLoginAt: null, twoFactorEnabled: true }, permissions: ['platform.firmware'], tenant: null };
const release = (isPublished: boolean) => ({ id: 'R1', version: '1.1.0', sha256: 'a'.repeat(64), size: 1009797, notes: null, isPublished, publishedAt: null });

describe('firmware page', () => {
  it('uploads a release as multipart and rolls a published one out', async () => {
    document.cookie = 'XSRF-TOKEN=t';
    let published = false;
    const api = mockApi({
      'GET /api/me': () => ({ status: 200, body: me }),
      'GET /api/super/notifications': () => ({ status: 200, body: { data: [], unread: 0, meta: { page: 1, lastPage: 1, total: 0 } } }),
      'GET /api/super/firmware': () => ({ status: 200, body: { data: [release(published)] } }),
      'POST /api/super/firmware': () => ({ status: 201, body: { data: release(false) } }),
      'POST /api/super/firmware/R1/publish': () => {
        published = true;
        return { status: 200, body: { data: release(true) } };
      },
      'POST /api/super/firmware/R1/rollout': () => ({ status: 200, body: { data: { queued: 3, skippedBusy: 1, alreadyCurrent: 2 } } }),
    });
    vi.stubGlobal('confirm', () => true);
    renderRoutes(routes, '/super/firmware');

    await userEvent.type(await screen.findByLabelText('Versiya (masalan 1.1.0)'), '1.1.0');
    await userEvent.upload(screen.getByLabelText(/Fayl/), new File([new Uint8Array([0xe9, 1, 2])], 'fw.bin'));
    await userEvent.click(screen.getByRole('button', { name: 'Yuklash' }));
    const upload = api.calls.find((c) => c.method === 'POST' && c.url.endsWith('/api/super/firmware'))!;
    expect(upload.init.body).toBeInstanceOf(FormData);
    expect((upload.init.body as FormData).get('version')).toBe('1.1.0');
    expect((upload.init.headers as Record<string, string>)['Content-Type']).toBeUndefined();

    await userEvent.click(await screen.findByRole('button', { name: 'Nashr qilish' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Qurilmalarga yuborish' }));
    expect(await screen.findByText(/Yuborildi: 3 ta/)).toBeInTheDocument();
  });
});

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { mockApi, withQuery } from '../../test/utils';
import { KioskQr, provisioningPayload, type KioskRelease } from './KioskQr';

afterEach(() => vi.unstubAllGlobals());

const release: KioskRelease = { version: '1.0.6', apk: 'nbx-kiosk.apk', signatureChecksum: 'abc-_DEF', component: 'uz.nbx.kiosk/uz.nbx.kiosk.AdminReceiver' };

describe('kiosk provisioning QR', () => {
  it('builds the Android device-owner payload from the release file', () => {
    const p = JSON.parse(provisioningPayload(release, 'https://nbx.itcode.uz', { ssid: ' Zal ', password: 'secret12' }));
    expect(p['android.app.extra.PROVISIONING_DEVICE_ADMIN_COMPONENT_NAME']).toBe('uz.nbx.kiosk/uz.nbx.kiosk.AdminReceiver');
    expect(p['android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_DOWNLOAD_LOCATION']).toBe('https://nbx.itcode.uz/kiosk/nbx-kiosk.apk');
    expect(p['android.app.extra.PROVISIONING_DEVICE_ADMIN_SIGNATURE_CHECKSUM']).toBe('abc-_DEF');
    expect(p['android.app.extra.PROVISIONING_WIFI_SSID']).toBe('Zal');
    expect(p['android.app.extra.PROVISIONING_WIFI_SECURITY_TYPE']).toBe('WPA');
    const noWifi = JSON.parse(provisioningPayload(release, 'https://x.uz', { ssid: '', password: '' }));
    expect(noWifi['android.app.extra.PROVISIONING_WIFI_SSID']).toBeUndefined();
  });

  it('shows the QR when the server carries the APK and explains when it does not', async () => {
    mockApi({ 'GET /kiosk/provisioning.json': () => ({ status: 200, body: release }) });
    render(withQuery(<KioskQr />));
    await userEvent.click(await screen.findByRole('button', { name: "QR kodni ko'rsatish" }));
    expect(screen.getByAltText('Kiosk sozlash QR kodi').getAttribute('src')).toMatch(/^data:image\/gif;base64,/);
    expect(screen.getByText('Kiosk ilova versiyasi: 1.0.6')).toBeInTheDocument();

    vi.unstubAllGlobals();
    mockApi({});
    render(withQuery(<KioskQr />));
    expect(await screen.findByText(/kiosk ilova \(APK\) hali yo'q/)).toBeInTheDocument();
  });
});

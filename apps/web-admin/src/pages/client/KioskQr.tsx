import { useQuery } from '@tanstack/react-query';
import qrcode from 'qrcode-generator';
import { useMemo, useState } from 'react';
import { TextField } from '../../components/Field';
import { Spinner } from '../../components/Feedback';
import { t } from '../../i18n';

/** Written by infrastructure/release/build-kiosk.sh next to the APK (public, no secrets). */
export interface KioskRelease {
  version: string;
  apk: string;
  signatureChecksum: string;
  component: string;
}

const useKioskRelease = () =>
  useQuery({
    queryKey: ['kiosk-release'],
    queryFn: async (): Promise<KioskRelease | null> => {
      const res = await fetch('/kiosk/provisioning.json', { credentials: 'omit', cache: 'no-store' });
      if (!res.ok) return null;
      try {
        return (await res.json()) as KioskRelease;
      } catch {
        return null; // the SPA fallback answered: no kiosk APK in this release
      }
    },
    staleTime: 60_000,
  });

/** Android's QR provisioning payload: installs our APK as Device Owner on a factory-reset tablet. */
export function provisioningPayload(r: KioskRelease, origin: string, wifi?: { ssid: string; password: string }): string {
  const payload: Record<string, string | boolean> = {
    'android.app.extra.PROVISIONING_DEVICE_ADMIN_COMPONENT_NAME': r.component,
    'android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_DOWNLOAD_LOCATION': `${origin}/kiosk/${r.apk}`,
    'android.app.extra.PROVISIONING_DEVICE_ADMIN_SIGNATURE_CHECKSUM': r.signatureChecksum,
    'android.app.extra.PROVISIONING_SKIP_ENCRYPTION': true,
    'android.app.extra.PROVISIONING_LEAVE_ALL_SYSTEM_APPS_ENABLED': true,
    'android.app.extra.PROVISIONING_TIME_ZONE': 'Asia/Tashkent',
  };
  if (wifi && wifi.ssid.trim()) {
    payload['android.app.extra.PROVISIONING_WIFI_SSID'] = wifi.ssid.trim();
    payload['android.app.extra.PROVISIONING_WIFI_SECURITY_TYPE'] = wifi.password ? 'WPA' : 'NONE';
    if (wifi.password) payload['android.app.extra.PROVISIONING_WIFI_PASSWORD'] = wifi.password;
  }
  return JSON.stringify(payload);
}

/** Setup QR for the locked kiosk APK. The Wi-Fi password stays in this page (never sent to the server). */
export function KioskQr() {
  const q = useKioskRelease();
  const [ssid, setSsid] = useState('');
  const [password, setPassword] = useState('');
  const [show, setShow] = useState(false);
  const payload = q.data ? provisioningPayload(q.data, window.location.origin, { ssid, password }) : '';
  const src = useMemo(() => {
    if (!payload) return '';
    const qr = qrcode(0, 'L');
    qr.addData(payload, 'Byte');
    qr.make();
    return qr.createDataURL(6, 4);
  }, [payload]);

  if (q.isPending) return <Spinner />;
  if (!q.data) return <p className="text-sm text-slate-600">{t('kiosk.notInRelease')}</p>;

  return (
    <div className="space-y-4">
      <ol className="list-decimal space-y-1 pl-5 text-sm text-slate-700">
        <li>{t('kiosk.step1')}</li>
        <li>{t('kiosk.step2')}</li>
        <li>{t('kiosk.step3')}</li>
        <li>{t('kiosk.step4')}</li>
      </ol>
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField label={t('kiosk.wifiSsid')} value={ssid} onChange={(e) => setSsid(e.target.value)} autoComplete="off" />
        <TextField label={t('kiosk.wifiPassword')} type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="off" />
      </div>
      <p className="text-xs text-slate-500">{t('kiosk.wifiHint')}</p>
      {show ? (
        <div className="space-y-2">
          <img src={src} alt={t('kiosk.qrAlt')} className="w-full max-w-md rounded-lg bg-white ring-1 ring-slate-200" />
          <p className="text-xs text-slate-500">{t('kiosk.version', { version: q.data.version })}</p>
        </div>
      ) : (
        <button type="button" onClick={() => setShow(true)} className="min-h-11 rounded-xl bg-brand-600 px-4 text-sm font-semibold text-white">
          {t('kiosk.showQr')}
        </button>
      )}
      <p className="text-xs text-slate-500">
        {t('kiosk.apkLink')}{' '}
        <a className="font-medium text-brand-700 underline" href={`/kiosk/${q.data.apk}`}>{q.data.apk}</a>
      </p>
    </div>
  );
}

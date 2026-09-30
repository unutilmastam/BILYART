import { useEffect, useState, type FormEvent } from 'react';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { ErrorBanner, Spinner, SuccessBanner } from '../../components/Feedback';
import { TextArea, TextField } from '../../components/Field';
import { useClientMutation, useTenantSettings } from '../../features/client/api';
import { t } from '../../i18n';
import { ApiError } from '../../lib/api';
import type { TenantSettings } from '../../types/api';

export function TenantSettingsPage() {
  const q = useTenantSettings();
  const m = useClientMutation<Partial<TenantSettings>>('/admin/settings', 'PUT');
  const [form, setForm] = useState<TenantSettings | null>(null);
  useEffect(() => { if (q.data) setForm(q.data); }, [q.data]);

  if (q.isPending || !form) return q.isError ? <ErrorBanner error={q.error} /> : <Spinner />;
  const err = (f: string) => (m.error instanceof ApiError ? m.error.fieldError(f) : undefined);
  const submit = (e: FormEvent) => { e.preventDefault(); m.mutate(form); };

  return (
    <Card title={t('nav.clientSettings')}>
      <form onSubmit={submit} className="max-w-2xl space-y-3" noValidate>
        <TextArea label={t('tsettings.privacyNotice')} value={form.privacyNotice} onChange={(e) => setForm({ ...form, privacyNotice: e.target.value })} error={err('privacyNotice')} />
        <div className="grid gap-3 sm:grid-cols-2">
          <TextField label={t('tsettings.photoRetentionDays')} type="number" min={1} max={365} value={form.photoRetentionDays} onChange={(e) => setForm({ ...form, photoRetentionDays: Number(e.target.value) })} error={err('photoRetentionDays')} />
          <TextField label={t('tsettings.warnBeforeMinutes')} type="number" min={1} max={30} value={form.warnBeforeMinutes} onChange={(e) => setForm({ ...form, warnBeforeMinutes: Number(e.target.value) })} error={err('warnBeforeMinutes')} />
        </div>
        <TextField label={t('tsettings.warningText')} value={form.warningText} onChange={(e) => setForm({ ...form, warningText: e.target.value })} error={err('warningText')} />
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="size-5" checked={form.operatorsCanViewPhotos} onChange={(e) => setForm({ ...form, operatorsCanViewPhotos: e.target.checked })} />{t('tsettings.operatorsCanViewPhotos')}</label>
        {m.isError && <ErrorBanner error={m.error} />}
        {m.isSuccess && <SuccessBanner>{t('settings.saved')}</SuccessBanner>}
        <Button type="submit" loading={m.isPending}>{t('common.save')}</Button>
      </form>
    </Card>
  );
}

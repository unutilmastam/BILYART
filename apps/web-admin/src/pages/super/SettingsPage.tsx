import { useEffect, useState, type FormEvent } from 'react';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { ErrorBanner, Spinner, SuccessBanner } from '../../components/Feedback';
import { TextArea, TextField } from '../../components/Field';
import { useSaveSettings, useSettings } from '../../features/super/api';
import { t } from '../../i18n';
import { ApiError } from '../../lib/api';

export function SettingsPage() {
  const q = useSettings();
  const save = useSaveSettings();
  const [form, setForm] = useState({ supportContact: '', paymentInstructions: '', defaultBranchLimit: '1', pricePerBranch: '0' });

  useEffect(() => {
    if (q.data) setForm({ supportContact: q.data.supportContact, paymentInstructions: q.data.paymentInstructions, defaultBranchLimit: String(q.data.defaultBranchLimit), pricePerBranch: String(q.data.pricePerBranch) });
  }, [q.data]);

  if (q.isPending) return <Spinner />;
  if (q.isError) return <ErrorBanner error={q.error} onRetry={() => q.refetch()} />;

  const submit = (e: FormEvent) => {
    e.preventDefault();
    save.mutate({ supportContact: form.supportContact, paymentInstructions: form.paymentInstructions, defaultBranchLimit: Number(form.defaultBranchLimit), pricePerBranch: Number(form.pricePerBranch) });
  };

  return (
    <Card title={t('nav.settings')}>
      <form onSubmit={submit} className="max-w-xl space-y-3" noValidate>
        <TextField label={t('settings.supportContact')} value={form.supportContact} onChange={(e) => setForm({ ...form, supportContact: e.target.value })} />
        <TextArea label={t('settings.paymentInstructions')} value={form.paymentInstructions} onChange={(e) => setForm({ ...form, paymentInstructions: e.target.value })} />
        <TextField label={t('settings.defaultBranchLimit')} type="number" min={1} value={form.defaultBranchLimit} onChange={(e) => setForm({ ...form, defaultBranchLimit: e.target.value })} />
        <TextField label={t('settings.pricePerBranch')} type="number" inputMode="numeric" min={0} step={1000} value={form.pricePerBranch} onChange={(e) => setForm({ ...form, pricePerBranch: e.target.value })} hint={t('settings.pricePerBranchHint')} error={save.error instanceof ApiError ? save.error.fieldError('pricePerBranch') : undefined} />
        {save.isError && <ErrorBanner error={save.error} />}
        {save.isSuccess && <SuccessBanner>{t('settings.saved')}</SuccessBanner>}
        <Button type="submit" loading={save.isPending}>{t('common.save')}</Button>
      </form>
    </Card>
  );
}

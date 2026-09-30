import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { ErrorBanner } from '../../components/Feedback';
import { SelectField, TextField } from '../../components/Field';
import { useCreateTenant } from '../../features/super/api';
import { t, tDynamic } from '../../i18n';
import { ApiError } from '../../lib/api';
import { parseMoneyInput } from '../../lib/format';
import { PAYMENT_METHODS, type PaymentMethod } from '../../types/api';

export function ClientCreatePage() {
  const navigate = useNavigate();
  const create = useCreateTenant();
  const [form, setForm] = useState({
    name: '', contactName: '', contactPhone: '', branchLimit: '1',
    ownerName: '', ownerLogin: '', ownerPassword: '',
    withPayment: true, amount: '', method: 'CASH' as PaymentMethod, days: '30', note: '',
  });
  const [amountError, setAmountError] = useState<string>();
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const err = (name: string) => (create.error instanceof ApiError ? create.error.fieldError(name) : undefined);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    let payment = null;
    if (form.withPayment) {
      const amount = parseMoneyInput(form.amount);
      if (amount === null) return setAmountError(t('payment.invalidAmount'));
      payment = { amount, method: form.method, days: Number(form.days), note: form.note || undefined };
    }
    setAmountError(undefined);
    create.mutate(
      {
        name: form.name,
        contactName: form.contactName || undefined,
        contactPhone: form.contactPhone || undefined,
        branchLimit: Number(form.branchLimit),
        owner: { name: form.ownerName, login: form.ownerLogin, password: form.ownerPassword },
        payment,
      },
      { onSuccess: (res) => navigate(`/super/clients/${res.data.id}`, { replace: true }) },
    );
  };

  return (
    <form onSubmit={submit} className="space-y-4" noValidate>
      <Card title={t('clients.new')}>
        <div className="grid gap-3 sm:grid-cols-2">
          <TextField label={t('clients.name')} value={form.name} onChange={set('name')} error={err('name')} required />
          <TextField label={t('clients.branchLimit')} type="number" inputMode="numeric" min={1} value={form.branchLimit} onChange={set('branchLimit')} error={err('branchLimit')} />
          <TextField label={t('clients.contactName')} value={form.contactName} onChange={set('contactName')} error={err('contactName')} />
          <TextField label={t('clients.contactPhone')} type="tel" value={form.contactPhone} onChange={set('contactPhone')} error={err('contactPhone')} />
        </div>
      </Card>
      <Card title={t('clients.owner')}>
        <div className="grid gap-3 sm:grid-cols-3">
          <TextField label={t('clients.ownerName')} value={form.ownerName} onChange={set('ownerName')} error={err('owner.name')} />
          <TextField label={t('clients.ownerLogin')} autoCapitalize="none" value={form.ownerLogin} onChange={set('ownerLogin')} error={err('owner.login')} />
          <TextField label={t('clients.ownerPassword')} type="password" autoComplete="new-password" value={form.ownerPassword} onChange={set('ownerPassword')} error={err('owner.password')} />
        </div>
      </Card>
      <Card title={t('clients.firstPayment')}>
        <label className="mb-3 flex items-center gap-2 text-sm">
          <input type="checkbox" className="size-5" checked={form.withPayment} onChange={(e) => setForm((f) => ({ ...f, withPayment: e.target.checked }))} />
          {t('actions.recordPayment')}
        </label>
        {form.withPayment && (
          <div className="grid gap-3 sm:grid-cols-3">
            <TextField label={t('payment.amount')} inputMode="numeric" value={form.amount} onChange={set('amount')} error={amountError ?? err('payment.amount')} />
            <SelectField label={t('payment.method')} value={form.method} onChange={set('method')} options={PAYMENT_METHODS.map((m) => ({ value: m, label: tDynamic('method', m) }))} />
            <TextField label={t('payment.days')} type="number" inputMode="numeric" min={1} value={form.days} onChange={set('days')} error={err('payment.days')} />
          </div>
        )}
      </Card>
      {create.isError && <ErrorBanner error={create.error} />}
      <div className="flex gap-2">
        <Button type="submit" loading={create.isPending}>{t('common.save')}</Button>
        <Button type="button" variant="secondary" onClick={() => navigate(-1)}>{t('common.cancel')}</Button>
      </div>
    </form>
  );
}

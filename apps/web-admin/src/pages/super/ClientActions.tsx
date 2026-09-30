import { useState, type FormEvent, type ReactNode } from 'react';
import { Button } from '../../components/Button';
import { ErrorBanner, SuccessBanner } from '../../components/Feedback';
import { SelectField, TextField } from '../../components/Field';
import { useResetOwnerPassword, useTenantAction } from '../../features/super/api';
import { t, tDynamic } from '../../i18n';
import { ApiError } from '../../lib/api';
import { parseMoneyInput } from '../../lib/format';
import { PAYMENT_METHODS, type Limits, type PaymentMethod, type Tenant } from '../../types/api';

function Panel({ title, children }: { title: string; children: ReactNode }) {
  return (
    <details className="group rounded-lg ring-1 ring-slate-200">
      <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between px-4 text-sm font-semibold">
        {title}
        <span className="text-slate-400 group-open:rotate-90">›</span>
      </summary>
      <div className="border-t border-slate-100 p-4">{children}</div>
    </details>
  );
}

const fieldErr = (e: unknown, name: string) => (e instanceof ApiError ? e.fieldError(name) : undefined);

function PaymentForm({ tenant }: { tenant: Tenant }) {
  const m = useTenantAction<{ amount: number; method: PaymentMethod; days: number; note?: string }>(tenant.id, '/payments');
  const [amount, setAmount] = useState('');
  const [method, setMethod] = useState<PaymentMethod>('CASH');
  const [days, setDays] = useState('30');
  const [note, setNote] = useState('');
  const [amountErr, setAmountErr] = useState<string>();

  const submit = (e: FormEvent) => {
    e.preventDefault();
    const value = parseMoneyInput(amount);
    if (value === null) return setAmountErr(t('payment.invalidAmount'));
    setAmountErr(undefined);
    m.mutate({ amount: value, method, days: Number(days), note: note || undefined }, { onSuccess: () => { setAmount(''); setNote(''); } });
  };

  return (
    <form onSubmit={submit} className="space-y-3" noValidate>
      <p className="text-xs text-slate-500">{t('payment.rule')}</p>
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField label={t('payment.amount')} inputMode="numeric" value={amount} onChange={(e) => setAmount(e.target.value)} error={amountErr ?? fieldErr(m.error, 'amount')} />
        <SelectField label={t('payment.method')} value={method} onChange={(e) => setMethod(e.target.value as PaymentMethod)} options={PAYMENT_METHODS.map((v) => ({ value: v, label: tDynamic('method', v) }))} />
        <TextField label={t('payment.days')} type="number" inputMode="numeric" min={1} value={days} onChange={(e) => setDays(e.target.value)} error={fieldErr(m.error, 'days')} />
        <TextField label={t('payment.note')} value={note} onChange={(e) => setNote(e.target.value)} />
      </div>
      {m.isError && !fieldErr(m.error, 'amount') && <ErrorBanner error={m.error} />}
      {m.isSuccess && <SuccessBanner>{t('actions.done')}</SuccessBanner>}
      <Button type="submit" loading={m.isPending}>{t('actions.recordPayment')}</Button>
    </form>
  );
}

function ExtendForm({ tenant }: { tenant: Tenant }) {
  const m = useTenantAction<{ days: number; reason: string }>(tenant.id, '/extend');
  const [days, setDays] = useState('7');
  const [reason, setReason] = useState('');
  return (
    <form onSubmit={(e) => { e.preventDefault(); m.mutate({ days: Number(days), reason }); }} className="space-y-3" noValidate>
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField label={t('payment.days')} type="number" min={1} value={days} onChange={(e) => setDays(e.target.value)} error={fieldErr(m.error, 'days')} />
        <TextField label={t('actions.reason')} value={reason} onChange={(e) => setReason(e.target.value)} error={fieldErr(m.error, 'reason')} />
      </div>
      {m.isSuccess && <SuccessBanner>{t('actions.done')}</SuccessBanner>}
      <Button type="submit" loading={m.isPending}>{t('actions.extend')}</Button>
    </form>
  );
}

function ExpiryForm({ tenant }: { tenant: Tenant }) {
  const m = useTenantAction<{ expiresAt: string; reason: string }>(tenant.id, '/expiry', 'PUT');
  const [date, setDate] = useState('');
  const [reason, setReason] = useState('');
  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        // End of the chosen day in Tashkent; the server stores UTC.
        m.mutate({ expiresAt: `${date}T23:59:59+05:00`, reason });
      }}
      className="space-y-3"
      noValidate
    >
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField label={t('clients.expires')} type="date" value={date} onChange={(e) => setDate(e.target.value)} error={fieldErr(m.error, 'expiresAt')} />
        <TextField label={t('actions.reason')} value={reason} onChange={(e) => setReason(e.target.value)} error={fieldErr(m.error, 'reason')} />
      </div>
      {m.isSuccess && <SuccessBanner>{t('actions.done')}</SuccessBanner>}
      <Button type="submit" loading={m.isPending} disabled={!date}>{t('actions.setExpiry')}</Button>
    </form>
  );
}

function LimitsForm({ tenant }: { tenant: Tenant }) {
  const m = useTenantAction<Limits>(tenant.id, '/limits', 'PUT');
  const toStr = (v: number | null) => (v === null ? '' : String(v));
  const [v, setV] = useState({
    branchLimit: String(tenant.limits.branchLimit),
    tableLimit: toStr(tenant.limits.tableLimit),
    deviceLimit: toStr(tenant.limits.deviceLimit),
    userLimit: toStr(tenant.limits.userLimit),
  });
  const num = (s: string) => (s.trim() === '' ? null : Number(s));
  const set = (k: keyof typeof v) => (e: { target: { value: string } }) => setV((x) => ({ ...x, [k]: e.target.value }));
  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        m.mutate({ branchLimit: Number(v.branchLimit), tableLimit: num(v.tableLimit), deviceLimit: num(v.deviceLimit), userLimit: num(v.userLimit) });
      }}
      className="space-y-3"
      noValidate
    >
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField label={t('clients.branchLimit')} type="number" min={1} value={v.branchLimit} onChange={set('branchLimit')} error={fieldErr(m.error, 'branchLimit')} />
        <TextField label={t('clients.tableLimit')} hint={t('common.none')} type="number" min={1} value={v.tableLimit} onChange={set('tableLimit')} error={fieldErr(m.error, 'tableLimit')} />
        <TextField label={t('clients.deviceLimit')} hint={t('common.none')} type="number" min={1} value={v.deviceLimit} onChange={set('deviceLimit')} error={fieldErr(m.error, 'deviceLimit')} />
        <TextField label={t('clients.userLimit')} hint={t('common.none')} type="number" min={1} value={v.userLimit} onChange={set('userLimit')} error={fieldErr(m.error, 'userLimit')} />
      </div>
      {m.isSuccess && <SuccessBanner>{t('actions.done')}</SuccessBanner>}
      <Button type="submit" loading={m.isPending}>{t('common.save')}</Button>
    </form>
  );
}

function StatusButtons({ tenant }: { tenant: Tenant }) {
  const suspend = useTenantAction<{ reason?: string }>(tenant.id, '/suspend');
  const activate = useTenantAction<{ reason?: string }>(tenant.id, '/activate');
  const deactivate = useTenantAction<{ reason?: string }>(tenant.id, '/deactivate');
  const reset = useResetOwnerPassword(tenant.id);
  const error = suspend.error ?? activate.error ?? deactivate.error ?? reset.error;

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-2">
        {tenant.statusFlag !== 'ACTIVE' && <Button onClick={() => activate.mutate({})} loading={activate.isPending}>{t('actions.activate')}</Button>}
        {tenant.statusFlag === 'ACTIVE' && <Button variant="secondary" onClick={() => suspend.mutate({})} loading={suspend.isPending}>{t('actions.suspend')}</Button>}
        {tenant.statusFlag !== 'DEACTIVATED' && (
          <Button variant="danger" loading={deactivate.isPending} onClick={() => window.confirm(t('actions.confirmDeactivate')) && deactivate.mutate({})}>
            {t('actions.deactivate')}
          </Button>
        )}
        <Button variant="ghost" onClick={() => reset.mutate()} loading={reset.isPending}>{t('actions.resetPassword')}</Button>
      </div>
      {reset.data && <SuccessBanner>{t('actions.tempPassword', { password: `${reset.data.login} / ${reset.data.temporaryPassword}` })}</SuccessBanner>}
      {error && <ErrorBanner error={error} />}
    </div>
  );
}

export function ClientActions({ tenant }: { tenant: Tenant }) {
  return (
    <div className="space-y-2">
      <StatusButtons tenant={tenant} />
      <Panel title={t('actions.recordPayment')}><PaymentForm tenant={tenant} /></Panel>
      <Panel title={t('actions.extend')}><ExtendForm tenant={tenant} /></Panel>
      <Panel title={t('actions.setExpiry')}><ExpiryForm tenant={tenant} /></Panel>
      <Panel title={t('actions.limits')}><LimitsForm key={JSON.stringify(tenant.limits)} tenant={tenant} /></Panel>
    </div>
  );
}

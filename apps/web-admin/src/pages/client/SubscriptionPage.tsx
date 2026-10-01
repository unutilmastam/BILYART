import { useState, type FormEvent } from 'react';
import { useMe } from '../../auth/useMe';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Empty, ErrorBanner, Spinner, SuccessBanner } from '../../components/Feedback';
import { TextField } from '../../components/Field';
import { PaymentRequestBadge, ReceiptView } from '../../components/PaymentRequestBadge';
import { DaysLeft, StatusBadge } from '../../components/StatusBadge';
import { receiptUrl, useCancelPaymentRequest, useClientSubscription, useMyPaymentRequests, useSendPaymentRequest } from '../../features/billing/api';
import { t } from '../../i18n';
import { ApiError } from '../../lib/api';
import { formatDate, formatDateTime, formatMoney } from '../../lib/format';
import type { Billing, PaymentRequest } from '../../types/api';

function PayForm({ billing, instructions }: { billing: Billing; instructions: string }) {
  const [months, setMonths] = useState(billing.monthOptions[0] ?? 1);
  const [file, setFile] = useState<File | null>(null);
  const [note, setNote] = useState('');
  const send = useSendPaymentRequest();
  const fieldError = (name: string) => (send.error instanceof ApiError ? send.error.fieldError(name) : undefined);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (file) send.mutate({ months, receipt: file, note: note.trim() || undefined });
  };

  return (
    <form onSubmit={submit} className="space-y-4" noValidate>
      <fieldset>
        <legend className="mb-2 text-[13px] font-semibold text-slate-700">{t('billing.period')}</legend>
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
          {billing.monthOptions.map((m) => (
            <label
              key={m}
              className={`cursor-pointer rounded-xl border p-3 text-center transition ${
                m === months ? 'border-brand-500 bg-brand-50 ring-2 ring-brand-500/20' : 'border-slate-200 hover:border-slate-300'
              }`}
            >
              <input type="radio" name="months" value={m} checked={m === months} onChange={() => setMonths(m)} className="sr-only" />
              <span className="block text-sm font-semibold text-slate-900">{t('billing.months', { n: m })}</span>
              <span className="block text-xs tabular text-slate-500">{formatMoney(billing.monthlyAmount * m)}</span>
            </label>
          ))}
        </div>
      </fieldset>

      <div className="rounded-xl bg-slate-50 p-3 text-sm ring-1 ring-slate-200">
        <p className="text-slate-600">{t('billing.toPay')}</p>
        <p className="text-2xl font-bold tabular text-slate-900">{formatMoney(billing.monthlyAmount * months)}</p>
        {instructions && <p className="mt-2 whitespace-pre-line text-slate-700">{instructions}</p>}
      </div>

      <div className="flex flex-col gap-1">
        <label htmlFor="receipt" className="text-[13px] font-semibold text-slate-700">{t('billing.receipt')}</label>
        <input
          id="receipt"
          type="file"
          accept="image/*"
          onChange={(e) => setFile(e.target.files?.[0] ?? null)}
          className="block w-full text-sm text-slate-700 file:mr-3 file:min-h-11 file:rounded-xl file:border-0 file:bg-brand-50 file:px-4 file:font-semibold file:text-brand-700"
        />
        <p className="text-xs text-slate-500">{t('billing.receiptHint')}</p>
        {fieldError('receipt') && <p role="alert" className="text-xs text-red-600">{fieldError('receipt')}</p>}
      </div>
      <TextField label={t('billing.note')} value={note} maxLength={500} onChange={(e) => setNote(e.target.value)} hint={t('billing.noteHint')} />

      {send.isError && <ErrorBanner error={send.error} />}
      <Button type="submit" loading={send.isPending} disabled={!file}>{t('billing.send')}</Button>
    </form>
  );
}

function PendingRequest({ request }: { request: PaymentRequest }) {
  const cancel = useCancelPaymentRequest();
  return (
    <div className="space-y-3">
      <SuccessBanner>{t('billing.pendingInfo')}</SuccessBanner>
      <p className="text-sm text-slate-700">
        {formatMoney(request.amount)} · {t('billing.months', { n: request.months })} · {formatDateTime(request.createdAt)}
      </p>
      <div className="flex flex-wrap gap-2">
        <ReceiptView src={receiptUrl('admin', request.id)} />
        <Button variant="ghost" loading={cancel.isPending} onClick={() => window.confirm(t('billing.confirmCancel')) && cancel.mutate(request.id)}>
          {t('billing.cancelRequest')}
        </Button>
      </div>
      {cancel.isError && <ErrorBanner error={cancel.error} />}
    </div>
  );
}

function History() {
  const q = useMyPaymentRequests();
  if (q.isPending) return <Spinner />;
  if (q.isError) return <ErrorBanner error={q.error} onRetry={() => q.refetch()} />;
  if (q.data.length === 0) return <Empty />;
  return (
    <ul className="divide-y divide-slate-100">
      {q.data.map((r) => (
        <li key={r.id} className="flex items-start justify-between gap-2 py-2.5 text-sm">
          <span className="min-w-0">
            <span className="font-semibold tabular">{formatMoney(r.amount)}</span>
            <span className="block text-xs text-slate-500">
              {formatDate(r.createdAt)} · {t('billing.months', { n: r.months })} · {t('billing.branchesN', { n: r.branchCount })}
            </span>
            {r.rejectReason && <span className="block text-xs text-red-700">{r.rejectReason}</span>}
          </span>
          <PaymentRequestBadge status={r.status} />
        </li>
      ))}
    </ul>
  );
}

/** Owner pays the monthly subscription: per active branch, then a Super Admin confirms the transfer. */
export function SubscriptionPage() {
  const me = useMe();
  const q = useClientSubscription();
  const canPay = me.data?.permissions.includes('billing.manage') ?? false;
  if (q.isPending) return <Spinner />;
  if (q.isError) return <ErrorBanner error={q.error} onRetry={() => q.refetch()} />;
  const s = q.data;
  const b = s.billing;

  return (
    <div className="space-y-4">
      <Card title={t('client.subscriptionTitle')} actions={<StatusBadge status={s.status} />}>
        <p className="text-sm text-slate-600">
          {t('clients.expires')}: <b className="text-slate-900">{formatDate(s.expiresAt)}</b> · <DaysLeft days={s.daysLeft} status={s.status} />
        </p>
        {b.pricePerBranch > 0 && (
          <p className="mt-2 text-sm text-slate-600">
            {t('billing.formula', { branches: b.branchCount, price: formatMoney(b.pricePerBranch) })} = <b className="text-slate-900">{formatMoney(b.monthlyAmount)}</b>
            {t('billing.perMonth')}
          </p>
        )}
      </Card>

      {canPay && (
        <Card title={t('billing.payTitle')} description={b.pending ? undefined : t('billing.payHint')}>
          {b.pending ? (
            <PendingRequest request={b.pending} />
          ) : b.pricePerBranch <= 0 ? (
            <p className="text-sm text-slate-600">
              {t('billing.notConfigured')}
              {s.supportContact && <> {t('client.contact')}: {s.supportContact}</>}
            </p>
          ) : (
            <PayForm billing={b} instructions={s.paymentInstructions} />
          )}
        </Card>
      )}

      {canPay && (
        <Card title={t('billing.history')}>
          <History />
        </Card>
      )}
    </div>
  );
}

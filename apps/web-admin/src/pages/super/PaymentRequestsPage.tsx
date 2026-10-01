import { useState } from 'react';
import { Link } from 'react-router';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Empty, ErrorBanner, Spinner } from '../../components/Feedback';
import { SelectField, TextField } from '../../components/Field';
import { PaymentRequestBadge, ReceiptView } from '../../components/PaymentRequestBadge';
import { receiptUrl, useReviewPaymentRequest, useSuperPaymentRequests } from '../../features/billing/api';
import { t, tDynamic } from '../../i18n';
import { formatDateTime, formatMoney } from '../../lib/format';
import { PAYMENT_METHODS, type PaymentMethod, type PaymentRequest, type PaymentRequestStatus } from '../../types/api';

const FILTERS: (PaymentRequestStatus | '')[] = ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED', ''];

function Review({ request }: { request: PaymentRequest }) {
  const [amount, setAmount] = useState(String(request.amount));
  const [method, setMethod] = useState<PaymentMethod>('CARD_TRANSFER');
  const [reason, setReason] = useState('');
  const [rejecting, setRejecting] = useState(false);
  const review = useReviewPaymentRequest();
  const parsed = Number(amount);
  const amountOk = Number.isInteger(parsed) && parsed >= 0;

  const approve = () => {
    if (!amountOk || !window.confirm(t('preq.confirmApprove', { amount: formatMoney(parsed), months: request.months }))) return;
    review.mutate({ id: request.id, action: 'approve', amount: parsed, method });
  };

  return (
    <div className="mt-3 space-y-3 rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
      <ReceiptView src={receiptUrl('super', request.id)} />
      {rejecting ? (
        <>
          <TextField label={t('preq.reason')} value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} />
          <div className="flex flex-wrap gap-2">
            <Button variant="danger" loading={review.isPending} disabled={reason.trim().length < 3} onClick={() => review.mutate({ id: request.id, action: 'reject', reason: reason.trim() })}>
              {t('preq.reject')}
            </Button>
            <Button variant="ghost" onClick={() => setRejecting(false)}>{t('common.cancel')}</Button>
          </div>
        </>
      ) : (
        <>
          <div className="grid gap-3 sm:grid-cols-2">
            <TextField label={t('preq.amountReceived')} type="number" inputMode="numeric" min={0} value={amount} onChange={(e) => setAmount(e.target.value)} />
            <SelectField label={t('preq.method')} value={method} onChange={(e) => setMethod(e.target.value as PaymentMethod)} options={PAYMENT_METHODS.map((m) => ({ value: m, label: tDynamic('method', m) }))} />
          </div>
          <p className="text-xs text-slate-500">{t('preq.checkHint')}</p>
          <div className="flex flex-wrap gap-2">
            <Button loading={review.isPending} disabled={!amountOk} onClick={approve}>{t('preq.approve')}</Button>
            <Button variant="secondary" onClick={() => setRejecting(true)}>{t('preq.reject')}…</Button>
          </div>
        </>
      )}
      {review.isError && <ErrorBanner error={review.error} />}
    </div>
  );
}

/** Super Admin: client "I paid" reports. Approve only after seeing the money on the account. */
export function PaymentRequestsPage() {
  const [status, setStatus] = useState<PaymentRequestStatus | ''>('PENDING');
  const [open, setOpen] = useState<string | null>(null);
  const q = useSuperPaymentRequests(status);

  return (
    <Card title={t('nav.paymentRequests')} description={t('preq.intro')}>
      <div className="mb-3 flex flex-wrap gap-2">
        {FILTERS.map((f) => (
          <button
            key={f || 'all'}
            type="button"
            onClick={() => setStatus(f)}
            className={`min-h-9 rounded-full px-3 text-sm font-semibold ring-1 ring-inset transition ${
              status === f ? 'bg-brand-600 text-white ring-brand-600' : 'bg-white text-slate-700 ring-slate-300 hover:bg-slate-50'
            }`}
          >
            {f ? tDynamic('preq', f) : t('common.all')}
          </button>
        ))}
      </div>
      {q.isPending ? <Spinner /> : q.isError ? <ErrorBanner error={q.error} onRetry={() => q.refetch()} /> : q.data.length === 0 ? <Empty /> : (
        <ul className="divide-y divide-slate-100">
          {q.data.map((r) => (
            <li key={r.id} className="py-3 text-sm">
              <div className="flex items-start justify-between gap-2">
                <span className="min-w-0">
                  {r.tenant && <Link to={`/super/clients/${r.tenant.id}`} className="font-semibold text-brand-700">{r.tenant.name}</Link>}
                  <span className="block text-xs text-slate-500">
                    {formatDateTime(r.createdAt)} · {t('billing.months', { n: r.months })} · {t('billing.formula', { branches: r.branchCount, price: formatMoney(r.pricePerBranch) })}
                  </span>
                  {r.note && <span className="block text-xs text-slate-700">“{r.note}”</span>}
                  {r.rejectReason && <span className="block text-xs text-red-700">{r.rejectReason}</span>}
                </span>
                <span className="flex shrink-0 flex-col items-end gap-1">
                  <span className="font-semibold tabular">{formatMoney(r.amount)}</span>
                  <PaymentRequestBadge status={r.status} />
                </span>
              </div>
              {r.status === 'PENDING' && (open === r.id ? <Review request={r} /> : (
                <Button variant="secondary" className="mt-2" onClick={() => setOpen(r.id)}>{t('preq.check')}</Button>
              ))}
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

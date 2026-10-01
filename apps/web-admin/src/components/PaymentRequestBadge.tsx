import { useState } from 'react';
import { tDynamic, t } from '../i18n';
import type { PaymentRequestStatus } from '../types/api';
import { Button } from './Button';
import { ErrorBanner } from './Feedback';

const tones: Record<PaymentRequestStatus, string> = {
  PENDING: 'bg-amber-50 text-amber-800 ring-amber-600/25',
  APPROVED: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  REJECTED: 'bg-red-50 text-red-700 ring-red-600/20',
  CANCELLED: 'bg-slate-100 text-slate-600 ring-slate-500/20',
};

export function PaymentRequestBadge({ status }: { status: PaymentRequestStatus }) {
  return <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset ${tones[status]}`}>{tDynamic('preq', status)}</span>;
}

/** Receipt image, loaded only on demand (it is a private file served with no-store). */
export function ReceiptView({ src }: { src: string }) {
  const [show, setShow] = useState(false);
  const [failed, setFailed] = useState(false);
  if (!show) return <Button variant="secondary" onClick={() => { setFailed(false); setShow(true); }}>{t('billing.showReceipt')}</Button>;
  if (failed) return <ErrorBanner error={new Error('receipt')} />;
  return (
    <a href={src} target="_blank" rel="noreferrer" className="block w-fit">
      <img src={src} alt={t('billing.receipt')} className="max-h-96 rounded-lg ring-1 ring-slate-200" onError={() => setFailed(true)} referrerPolicy="no-referrer" />
    </a>
  );
}

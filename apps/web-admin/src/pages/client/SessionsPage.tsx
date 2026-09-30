import { useState } from 'react';
import { Link } from 'react-router';
import { Card } from '../../components/Card';
import { PaymentChip, SessionStatusChip } from '../../components/Chips';
import { Empty, ErrorBanner, Spinner } from '../../components/Feedback';
import { SelectField } from '../../components/Field';
import { Pagination } from '../../components/Pagination';
import { useSessions } from '../../features/client/sessions';
import { t, tDynamic } from '../../i18n';
import { formatDateTime, formatMoney, formatTime } from '../../lib/format';

const STATUSES = ['', 'ACTIVE', 'COMPLETED', 'COMPLETING', 'STARTING', 'RESERVED', 'CANCELLED', 'FAILED'];
const PAYMENTS = ['', 'UNPAID', 'PAID', 'WAIVED'];

export function SessionsPage() {
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const [payment, setPayment] = useState('');
  const q = useSessions({ page, status: status || undefined, payment: payment || undefined });

  return (
    <Card title={t('nav.sessions')}>
      <div className="mb-3 grid gap-3 sm:grid-cols-2">
        <SelectField label="Holat" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} options={STATUSES.map((s) => ({ value: s, label: s ? tDynamic('sstatus', s) : t('common.all') }))} />
        <SelectField label={t('sess.payment')} value={payment} onChange={(e) => { setPayment(e.target.value); setPage(1); }} options={PAYMENTS.map((s) => ({ value: s, label: s ? tDynamic('pay', s) : t('common.all') }))} />
      </div>
      {q.isPending ? <Spinner /> : q.isError ? <ErrorBanner error={q.error} onRetry={() => q.refetch()} /> : q.data.data.length === 0 ? <Empty /> : (
        <>
          <ul className="divide-y divide-slate-100">
            {q.data.data.map((s) => (
              <li key={s.id}>
                <Link to={`/client/sessions/${s.id}`} className="flex items-center justify-between gap-3 py-3 hover:bg-slate-50">
                  <span className="min-w-0">
                    <span className="block font-medium">{s.table?.name} · {s.branch?.name}</span>
                    <span className="block text-xs text-slate-500">
                      {s.startAt ? `${formatDateTime(s.startAt)}–${formatTime(s.endedAt ?? s.endAt)}` : formatDateTime(s.createdAt)} · {t('sess.minutes', { n: s.durationMinutes })}
                    </span>
                  </span>
                  <span className="flex flex-col items-end gap-1 text-xs">
                    <span className="font-semibold">{formatMoney(s.amount)}</span>
                    <SessionStatusChip status={s.effectiveStatus} />
                    <PaymentChip status={s.paymentStatus} />
                  </span>
                </Link>
              </li>
            ))}
          </ul>
          <Pagination {...q.data.meta} onPage={setPage} />
        </>
      )}
    </Card>
  );
}

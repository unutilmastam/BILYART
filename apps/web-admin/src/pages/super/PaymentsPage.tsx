import { useState } from 'react';
import { Link } from 'react-router';
import { Card } from '../../components/Card';
import { Empty, ErrorBanner, Spinner } from '../../components/Feedback';
import { Pagination } from '../../components/Pagination';
import { usePayments } from '../../features/super/api';
import { t, tDynamic } from '../../i18n';
import { formatDate, formatMoney } from '../../lib/format';
import type { Payment } from '../../types/api';

export function PaymentList({ payments }: { payments: Payment[] }) {
  if (payments.length === 0) return <Empty />;
  return (
    <ul className="divide-y divide-slate-100">
      {payments.map((p) => (
        <li key={p.id} className="flex items-center justify-between gap-2 py-2 text-sm">
          <span className="min-w-0">
            {p.tenant && (
              <Link to={`/super/clients/${p.tenant.id}`} className="font-medium text-brand-700">
                {p.tenant.name}
              </Link>
            )}
            <span className="block text-xs text-slate-500">
              {formatDate(p.paidAt)} · {tDynamic('method', p.method)}
              {p.note ? ` · ${p.note}` : ''}
            </span>
          </span>
          <span className="whitespace-nowrap font-semibold">{formatMoney(p.amount)}</span>
        </li>
      ))}
    </ul>
  );
}

export function PaymentsPage() {
  const [page, setPage] = useState(1);
  const q = usePayments({ page });
  return (
    <Card title={t('nav.payments')} actions={q.data?.meta.sum !== undefined && <span className="text-sm font-semibold">{formatMoney(q.data.meta.sum)}</span>}>
      {q.isPending ? <Spinner /> : q.isError ? <ErrorBanner error={q.error} onRetry={() => q.refetch()} /> : (
        <>
          <PaymentList payments={q.data.data} />
          <Pagination {...q.data.meta} onPage={setPage} />
        </>
      )}
    </Card>
  );
}

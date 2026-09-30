import { useState } from 'react';
import { Link } from 'react-router';
import { Card } from '../../components/Card';
import { Empty, ErrorBanner, Spinner } from '../../components/Feedback';
import { SelectField, TextField } from '../../components/Field';
import { Pagination } from '../../components/Pagination';
import { DaysLeft, StatusBadge } from '../../components/StatusBadge';
import { useTenants } from '../../features/super/api';
import { t, tDynamic } from '../../i18n';
import { formatDate } from '../../lib/format';
import { SUBSCRIPTION_STATUSES, type SubscriptionStatus } from '../../types/api';

export function ClientsPage() {
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState<SubscriptionStatus | ''>('');
  const [q, setQ] = useState('');
  const query = useTenants({ page, status, q: q.trim() || undefined });

  return (
    <Card
      title={t('nav.clients')}
      actions={
        <Link to="/super/clients/new" className="inline-flex min-h-11 items-center rounded-lg bg-brand-700 px-4 text-sm font-semibold text-white">
          + {t('clients.new')}
        </Link>
      }
    >
      <div className="mb-3 grid gap-3 sm:grid-cols-2">
        <TextField label={t('common.search')} value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
        <SelectField
          label="Holat"
          value={status}
          onChange={(e) => { setStatus(e.target.value as SubscriptionStatus | ''); setPage(1); }}
          options={[{ value: '', label: t('common.all') }, ...SUBSCRIPTION_STATUSES.map((s) => ({ value: s, label: tDynamic('status', s) }))]}
        />
      </div>
      {query.isPending ? <Spinner /> : query.isError ? <ErrorBanner error={query.error} onRetry={() => query.refetch()} /> : query.data.data.length === 0 ? <Empty /> : (
        <>
          <ul className="divide-y divide-slate-100">
            {query.data.data.map((c) => (
              <li key={c.id}>
                <Link to={`/super/clients/${c.id}`} className="flex items-center justify-between gap-3 py-3 hover:bg-slate-50">
                  <span className="min-w-0">
                    <span className="block truncate font-medium">{c.name}</span>
                    <span className="block text-xs text-slate-500">
                      {t('clients.expires')}: {formatDate(c.subscription.expiresAt)} · {t('clients.branches')}: {c.usage?.branches ?? 0}/{c.limits.branchLimit}
                    </span>
                  </span>
                  <span className="flex flex-col items-end gap-1 text-xs">
                    <StatusBadge status={c.subscription.status} />
                    <DaysLeft days={c.subscription.daysLeft} status={c.subscription.status} />
                  </span>
                </Link>
              </li>
            ))}
          </ul>
          <Pagination {...query.data.meta} onPage={setPage} />
        </>
      )}
    </Card>
  );
}

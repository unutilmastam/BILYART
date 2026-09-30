import { Link, useParams } from 'react-router';
import { Card } from '../../components/Card';
import { Empty, ErrorBanner, Spinner } from '../../components/Feedback';
import { DaysLeft, StatusBadge } from '../../components/StatusBadge';
import { useHistory, useTenant } from '../../features/super/api';
import { t } from '../../i18n';
import { formatDate, formatDateTime } from '../../lib/format';
import { ClientActions } from './ClientActions';
import { PaymentList } from './PaymentsPage';

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex justify-between gap-3 py-1.5 text-sm">
      <dt className="text-slate-500">{label}</dt>
      <dd className="text-right font-medium">{children}</dd>
    </div>
  );
}

export function ClientDetailPage() {
  const { id = '' } = useParams();
  const tenant = useTenant(id);
  const history = useHistory(id);

  if (tenant.isPending) return <Spinner />;
  if (tenant.isError) return <ErrorBanner error={tenant.error} onRetry={() => tenant.refetch()} />;
  const c = tenant.data;
  const lim = (v: number | null) => (v === null ? t('common.none') : v);

  return (
    <div className="space-y-4">
      <Link to="/super/clients" className="text-sm text-brand-700">‹ {t('common.back')}</Link>
      <div className="grid gap-4 lg:grid-cols-2">
        <Card title={c.name} actions={<StatusBadge status={c.subscription.status} />}>
          <dl className="divide-y divide-slate-100">
            <Row label={t('clients.expires')}>{formatDate(c.subscription.expiresAt)}</Row>
            <Row label={t('clients.daysLeft')}><DaysLeft days={c.subscription.daysLeft} status={c.subscription.status} /></Row>
            <Row label={t('clients.contactName')}>{c.contactName ?? '—'}</Row>
            <Row label={t('clients.contactPhone')}>{c.contactPhone ? <a href={`tel:${c.contactPhone}`} className="text-brand-700">{c.contactPhone}</a> : '—'}</Row>
            <Row label={t('clients.branches')}>{c.usage?.branches ?? 0} / {c.limits.branchLimit}</Row>
            <Row label={t('clients.tables')}>{c.usage?.tables ?? 0} / {lim(c.limits.tableLimit)}</Row>
            <Row label={t('clients.devices')}>{c.usage?.devices ?? 0} / {lim(c.limits.deviceLimit)}</Row>
            <Row label={t('clients.users')}>{c.usage?.users ?? 0} / {lim(c.limits.userLimit)}</Row>
          </dl>
        </Card>
        <Card>
          <ClientActions tenant={c} />
        </Card>
      </div>
      <Card title={t('history.payments')}>
        {history.isPending ? <Spinner /> : history.isError ? <ErrorBanner error={history.error} /> : <PaymentList payments={history.data.payments} />}
      </Card>
      <Card title={t('history.events')}>
        {history.data && history.data.events.length === 0 && <Empty />}
        <ul className="divide-y divide-slate-100 text-sm">
          {history.data?.events.map((e, i) => (
            <li key={i} className="flex justify-between gap-2 py-2">
              <span className="font-mono text-xs">{e.type}</span>
              <time className="text-xs text-slate-500">{formatDateTime(e.createdAt)}</time>
            </li>
          ))}
        </ul>
      </Card>
    </div>
  );
}

import { useQuery } from '@tanstack/react-query';
import { useMe } from '../../auth/useMe';
import { Card, Stat } from '../../components/Card';
import { TableStatusChip } from '../../components/Chips';
import { ErrorBanner, Spinner } from '../../components/Feedback';
import { DaysLeft, StatusBadge } from '../../components/StatusBadge';
import { useClientDashboard } from '../../features/client/sessions';
import { t } from '../../i18n';
import { api } from '../../lib/api';
import { formatDate, formatMinutes, formatMoney, formatTime } from '../../lib/format';
import type { ClientSubscription } from '../../types/api';

function SubscriptionCard() {
  const q = useQuery({ queryKey: ['client', 'subscription'], queryFn: () => api<ClientSubscription>('/admin/subscription') });
  if (q.isPending) return <Spinner />;
  if (q.isError) return <ErrorBanner error={q.error} onRetry={() => q.refetch()} />;
  const s = q.data;
  const inactive = s.status === 'EXPIRED' || s.status === 'SUSPENDED' || s.status === 'DEACTIVATED';
  return (
    <Card title={t('client.subscriptionTitle')} actions={<StatusBadge status={s.status} />}>
      <p className="text-sm text-slate-600">
        {t('clients.expires')}: <b>{formatDate(s.expiresAt)}</b> · <DaysLeft days={s.daysLeft} status={s.status} />
      </p>
      {inactive && (
        <div className="mt-3 space-y-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200">
          <p className="font-semibold">{t('client.inactive')}</p>
          {s.paymentInstructions && <p className="whitespace-pre-line">{s.paymentInstructions}</p>}
          {s.supportContact && <p>{t('client.contact')}: {s.supportContact}</p>}
        </div>
      )}
    </Card>
  );
}

function LiveDashboard() {
  const q = useClientDashboard();
  if (q.isPending) return <Spinner />;
  if (q.isError) return <ErrorBanner error={q.error} onRetry={() => q.refetch()} />;
  const d = q.data;
  return (
    <>
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Stat label={t('cdash.playing')} value={d.totals.playing} />
        <Stat label={t('cdash.available')} value={d.totals.available} />
        <Stat label={t('cdash.sessionsToday')} value={d.totals.sessionsToday} />
        <Stat label={t('cdash.amountToday')} value={formatMoney(d.totals.amountToday)} />
        <Stat label={t('cdash.minutesToday')} value={formatMinutes(d.totals.minutesToday)} />
        <Stat label={t('cdash.unpaid')} value={d.totals.unpaidToday} tone={d.totals.unpaidToday ? 'warn' : 'default'} />
        <Stat label={t('cdash.devices')} value={`${d.totals.devicesOnline} / ${d.totals.devicesOffline}`} tone={d.totals.devicesOffline ? 'bad' : 'default'} />
      </div>
      {d.branches.map((b) => (
        <Card key={b.branch.id} title={b.branch.name}>
          <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
            {b.tables.map((tb) => (
              <li key={tb.id} className="rounded-lg p-3 ring-1 ring-slate-200">
                <p className="font-semibold">{tb.name}</p>
                <TableStatusChip status={tb.status} />
                {tb.endAt && <p className="mt-1 text-xs text-slate-500">{formatTime(tb.endAt)} {t('cdash.until')}</p>}
              </li>
            ))}
          </ul>
        </Card>
      ))}
    </>
  );
}

/** Client home: subscription status (always visible, spec §29) + live dashboard when permitted. */
export function ClientHomePage() {
  const me = useMe();
  const active = me.data?.tenant?.subscription.status === 'ACTIVE' || me.data?.tenant?.subscription.status === 'EXPIRING_SOON';
  return (
    <div className="space-y-4">
      <SubscriptionCard />
      {active && me.data?.permissions.includes('sessions.view') && <LiveDashboard />}
      {me.data?.permissions.includes('tenant.export') && (
        <a href="/api/admin/export" className="inline-flex min-h-11 items-center rounded-lg px-4 text-sm font-semibold text-brand-700 ring-1 ring-slate-300">
          ⬇ {t('notif.export')}
        </a>
      )}
    </div>
  );
}

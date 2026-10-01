import { AlertCircle, CircleDollarSign, Clock3, Cpu, Download, ListChecks, PlayCircle, Square } from 'lucide-react';
import { Link } from 'react-router';
import { useMe } from '../../auth/useMe';
import { Card, Stat } from '../../components/Card';
import { TableStatusChip } from '../../components/Chips';
import { ErrorBanner, Spinner } from '../../components/Feedback';
import { DaysLeft, StatusBadge } from '../../components/StatusBadge';
import { useClientSubscription } from '../../features/billing/api';
import { useClientDashboard } from '../../features/client/sessions';
import { t } from '../../i18n';
import { formatDate, formatMinutes, formatMoney, formatTime } from '../../lib/format';
import type { ClientDashboard, TableStatusValue } from '../../types/api';

function SubscriptionCard() {
  const q = useClientSubscription();
  const canPay = useMe().data?.permissions.includes('billing.manage') ?? false;
  if (q.isPending) return <Spinner />;
  if (q.isError) return <ErrorBanner error={q.error} onRetry={() => q.refetch()} />;
  const s = q.data;
  const inactive = s.status === 'EXPIRED' || s.status === 'SUSPENDED' || s.status === 'DEACTIVATED';
  return (
    <Card title={t('client.subscriptionTitle')} actions={<StatusBadge status={s.status} />}>
      <p className="text-sm text-slate-600">
        {t('clients.expires')}: <b className="text-slate-900">{formatDate(s.expiresAt)}</b> · <DaysLeft days={s.daysLeft} status={s.status} />
      </p>
      {inactive && (
        <div className="mt-3 space-y-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200">
          <p className="font-semibold">{t('client.inactive')}</p>
          {s.paymentInstructions && <p className="whitespace-pre-line">{s.paymentInstructions}</p>}
          {s.supportContact && <p>{t('client.contact')}: {s.supportContact}</p>}
        </div>
      )}
      {canPay && (inactive || s.status === 'EXPIRING_SOON') && (
        <Link to="/client/subscription" className="mt-3 inline-flex min-h-11 items-center rounded-xl bg-gradient-to-b from-brand-600 to-brand-700 px-4 text-sm font-semibold text-white shadow-sm">
          {s.billing.pending ? t('billing.pendingShort') : t('billing.payNow')}
        </Link>
      )}
    </Card>
  );
}

const stripe: Record<TableStatusValue, string> = {
  AVAILABLE: 'from-emerald-400 to-emerald-500',
  RESERVED: 'from-sky-400 to-sky-500',
  STARTING: 'from-sky-400 to-sky-500',
  BUSY: 'from-rose-400 to-rose-500',
  WARNING: 'from-amber-300 to-amber-500',
  DISABLED: 'from-slate-200 to-slate-300',
  DEVICE_OFFLINE: 'from-slate-300 to-slate-400',
  CLOSED: 'from-slate-200 to-slate-300',
};

function TableTile({ tb }: { tb: ClientDashboard['branches'][number]['tables'][number] }) {
  const playing = tb.status === 'BUSY' || tb.status === 'WARNING' || tb.status === 'STARTING';
  return (
    <li className="relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-3.5 shadow-card">
      <span className={`absolute inset-x-0 top-0 h-1 bg-gradient-to-r ${stripe[tb.status]}`} aria-hidden />
      <div className="flex items-center gap-3">
        <span
          className={`grid size-10 shrink-0 place-items-center rounded-full text-sm font-bold tabular ${
            playing ? 'bg-gradient-to-br from-brand-600 to-brand-800 text-white shadow-sm' : 'bg-slate-100 text-slate-600'
          }`}
          aria-hidden
        >
          {tb.number}
        </span>
        <p className="min-w-0 truncate font-semibold text-slate-900">{tb.name}</p>
      </div>
      <div className="mt-2.5">
        <TableStatusChip status={tb.status} />
      </div>
      {tb.endAt && (
        <p className="mt-2 flex items-center gap-1.5 text-xs text-slate-500">
          <Clock3 className="size-3.5" aria-hidden />
          <span className="tabular font-medium text-slate-700">{formatTime(tb.endAt)}</span> {t('cdash.until')}
        </p>
      )}
    </li>
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
        <Stat label={t('cdash.playing')} value={d.totals.playing} icon={PlayCircle} tone="brand" />
        <Stat label={t('cdash.available')} value={d.totals.available} icon={Square} tone="info" />
        <Stat label={t('cdash.sessionsToday')} value={d.totals.sessionsToday} icon={ListChecks} tone="default" />
        <Stat label={t('cdash.amountToday')} value={formatMoney(d.totals.amountToday)} icon={CircleDollarSign} tone="accent" />
        <Stat label={t('cdash.minutesToday')} value={formatMinutes(d.totals.minutesToday)} icon={Clock3} tone="default" />
        <Stat label={t('cdash.unpaid')} value={d.totals.unpaidToday} icon={AlertCircle} tone={d.totals.unpaidToday ? 'warn' : 'default'} />
        <Stat label={t('cdash.devices')} value={`${d.totals.devicesOnline} / ${d.totals.devicesOffline}`} icon={Cpu} tone={d.totals.devicesOffline ? 'bad' : 'brand'} />
      </div>
      {d.branches.map((b) => (
        <Card key={b.branch.id} title={b.branch.name} description={`${t('cdash.playing')}: ${b.playing} · ${t('cdash.available')}: ${b.available}`}>
          {b.tables.length === 0 ? (
            <p className="text-sm text-slate-500">{t('common.empty')}</p>
          ) : (
            <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
              {b.tables.map((tb) => <TableTile key={tb.id} tb={tb} />)}
            </ul>
          )}
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
        <a href="/api/admin/export" className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-white px-4 text-sm font-semibold text-brand-700 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50">
          <Download className="size-4" aria-hidden /> {t('notif.export')}
        </a>
      )}
    </div>
  );
}

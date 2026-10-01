import { AlarmClock, Ban, CalendarClock, CheckCircle2, Cpu, Landmark, TrendingUp, Users } from 'lucide-react';
import { Card, Stat } from '../../components/Card';
import { ErrorBanner, Spinner } from '../../components/Feedback';
import { useDashboard } from '../../features/super/api';
import { t, tDynamic } from '../../i18n';
import { formatMoney } from '../../lib/format';
import { AuditList } from './AuditLogPage';

export function DashboardPage() {
  const q = useDashboard();
  if (q.isPending) return <Spinner />;
  if (q.isError) return <ErrorBanner error={q.error} onRetry={() => q.refetch()} />;
  const d = q.data;

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Stat label={t('dash.clients')} value={d.tenants.total} icon={Users} tone="brand" />
        <Stat label={tDynamic('status', 'ACTIVE')} value={d.tenants.active} icon={CheckCircle2} tone="brand" />
        <Stat label={tDynamic('status', 'EXPIRING_SOON')} value={d.tenants.expiringSoon} tone={d.tenants.expiringSoon ? 'warn' : 'default'} icon={AlarmClock} />
        <Stat label={tDynamic('status', 'EXPIRED')} value={d.tenants.expired} tone={d.tenants.expired ? 'bad' : 'default'} icon={CalendarClock} />
        <Stat label={tDynamic('status', 'SUSPENDED')} value={d.tenants.suspended} icon={Ban} />
        <Stat label={t('dash.revenueMonth')} value={formatMoney(d.revenue.thisMonth)} icon={TrendingUp} tone="accent" />
        <Stat label={t('dash.revenueTotal')} value={formatMoney(d.revenue.total)} icon={Landmark} tone="accent" />
        <Stat label={t('dash.devices')} value={`${d.devices.online} / ${d.devices.paired}`} tone={d.devices.online < d.devices.paired ? 'warn' : 'brand'} icon={Cpu} />
      </div>
      <Card title={t('dash.recent')}>
        <AuditList entries={d.recentAudit} />
      </Card>
    </div>
  );
}

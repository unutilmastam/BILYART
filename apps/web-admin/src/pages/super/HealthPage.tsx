import { useQuery } from '@tanstack/react-query';
import { Card } from '../../components/Card';
import { ErrorBanner, Spinner } from '../../components/Feedback';
import { t } from '../../i18n';
import type { MessageKey } from '../../i18n/uz';
import { api } from '../../lib/api';
import { formatDateTime } from '../../lib/format';

type Level = 'ok' | 'warn' | 'fail';
interface Health { status: Level; checkedAt: string; checks: Record<'db' | 'storage' | 'messaging' | 'backups', { status: Level } & Record<string, unknown>> }

const tone: Record<Level, string> = { ok: 'bg-emerald-100 text-emerald-800', warn: 'bg-amber-100 text-amber-900', fail: 'bg-red-600 text-white' };

function Badge({ level }: { level: Level }) {
  return <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${tone[level]}`}>{t(`health.${level}` as MessageKey)}</span>;
}

function Details({ data }: { data: Record<string, unknown> }) {
  const rows = Object.entries(data).filter(([k]) => k !== 'status');
  return (
    <dl className="mt-2 space-y-1 text-sm">
      {rows.map(([k, v]) => (
        <div key={k} className="flex justify-between gap-3">
          <dt className="text-slate-500">{k}</dt>
          <dd className="text-right font-mono text-xs">{v && typeof v === 'object' ? ((v as { at?: string }).at ? `${formatDateTime((v as { at: string }).at)} · ${(v as { ok?: boolean }).ok ? 'OK' : 'XATO'}` : JSON.stringify(v)) : String(v ?? '—')}</dd>
        </div>
      ))}
    </dl>
  );
}

export function HealthPage() {
  const q = useQuery({ queryKey: ['super', 'health'], queryFn: () => api<Health>('/super/health'), refetchInterval: 30_000 });
  if (q.isPending) return <Spinner />;
  if (q.isError) return <ErrorBanner error={q.error} onRetry={() => q.refetch()} />;
  return (
    <div className="space-y-4">
      <Card title={t('nav.health')} actions={<Badge level={q.data.status} />}>
        <p className="text-xs text-slate-500">{formatDateTime(q.data.checkedAt)}</p>
      </Card>
      <div className="grid gap-4 md:grid-cols-2">
        {(['db', 'storage', 'messaging', 'backups'] as const).map((k) => (
          <Card key={k} title={t(`health.${k}` as MessageKey)} actions={<Badge level={q.data.checks[k].status} />}>
            <Details data={q.data.checks[k]} />
          </Card>
        ))}
      </div>
    </div>
  );
}

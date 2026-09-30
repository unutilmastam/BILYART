import { useState } from 'react';
import { Card, Stat } from '../../components/Card';
import { ErrorBanner, Spinner } from '../../components/Feedback';
import { TextField } from '../../components/Field';
import { useReport } from '../../features/client/sessions';
import { t } from '../../i18n';
import { formatMinutes, formatMoney } from '../../lib/format';

function todayLocal(): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Tashkent' }).format(new Date());
}

export function ReportsPage() {
  const [kind, setKind] = useState<'daily' | 'monthly'>('daily');
  const [date, setDate] = useState(todayLocal());
  const [month, setMonth] = useState(todayLocal().slice(0, 7));
  const q = useReport(kind, kind === 'daily' ? date : month);

  return (
    <div className="space-y-4">
      <Card>
        <div className="flex flex-wrap items-end gap-3">
          <div className="flex rounded-lg ring-1 ring-slate-300">
            {(['daily', 'monthly'] as const).map((k) => (
              <button key={k} type="button" onClick={() => setKind(k)} className={`min-h-11 px-4 text-sm font-semibold ${kind === k ? 'bg-brand-700 text-white' : ''}`}>
                {k === 'daily' ? t('rep.daily') : t('rep.monthly')}
              </button>
            ))}
          </div>
          {kind === 'daily'
            ? <TextField label={t('rep.date')} type="date" value={date} onChange={(e) => setDate(e.target.value)} />
            : <TextField label={t('rep.month')} type="month" value={month} onChange={(e) => setMonth(e.target.value)} />}
        </div>
      </Card>
      {q.isPending ? <Spinner /> : q.isError ? <ErrorBanner error={q.error} onRetry={() => q.refetch()} /> : (
        <>
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <Stat label={t('rep.sessions')} value={q.data.totals.sessions} />
            <Stat label={t('rep.hours')} value={formatMinutes(q.data.totals.minutes)} />
            <Stat label={t('rep.amount')} value={formatMoney(q.data.totals.amount)} />
            <Stat label={t('rep.unpaid')} value={`${q.data.totals.unpaidCount} · ${formatMoney(q.data.totals.unpaidAmount)}`} tone={q.data.totals.unpaidCount ? 'warn' : 'default'} />
          </div>
          {q.data.branches.map((b) => (
            <Card key={b.branch.id} title={`${b.branch.name} · ${t('rep.utilization')} ${b.utilizationPercent}%`}>
              <table className="w-full text-sm">
                <thead className="text-left text-xs text-slate-500">
                  <tr><th className="py-1">{t('sess.table')}</th><th>{t('rep.sessions')}</th><th>{t('rep.hours')}</th><th className="text-right">{t('rep.amount')}</th></tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {b.tables.map((tb) => (
                    <tr key={tb.id}>
                      <td className="py-1.5">{tb.name}</td>
                      <td>{tb.sessions}</td>
                      <td>{formatMinutes(tb.minutes)}</td>
                      <td className="text-right font-medium">{formatMoney(tb.amount)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Card>
          ))}
        </>
      )}
    </div>
  );
}

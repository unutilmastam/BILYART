import { Link, useParams } from 'react-router';
import { useMe } from '../../auth/useMe';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { PaymentChip, SessionStatusChip } from '../../components/Chips';
import { ErrorBanner, Spinner } from '../../components/Feedback';
import { useMarkPayment, useSession, useStopSession } from '../../features/client/sessions';
import { t, tDynamic } from '../../i18n';
import { formatDateTime, formatMoney } from '../../lib/format';
import type { PaymentStatusValue } from '../../types/api';

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex justify-between gap-3 py-1.5 text-sm">
      <dt className="text-slate-500">{label}</dt>
      <dd className="text-right font-medium">{children}</dd>
    </div>
  );
}

export function SessionDetailPage() {
  const { id = '' } = useParams();
  const me = useMe();
  const q = useSession(id);
  const stop = useStopSession(id);
  const pay = useMarkPayment(id);
  const perms = me.data?.permissions ?? [];

  if (q.isPending) return <Spinner />;
  if (q.isError) return <ErrorBanner error={q.error} onRetry={() => q.refetch()} />;
  const s = q.data;
  const running = s.effectiveStatus === 'ACTIVE' || s.effectiveStatus === 'STARTING';
  const payable = !['RESERVED', 'CANCELLED', 'FAILED'].includes(s.status);

  return (
    <div className="space-y-4">
      <Link to="/client/sessions" className="text-sm text-brand-700">‹ {t('common.back')}</Link>
      <Card title={`${s.table?.name ?? ''} · ${s.branch?.name ?? ''}`} actions={<SessionStatusChip status={s.effectiveStatus} />}>
        <dl className="divide-y divide-slate-100">
          <Row label={t('sess.time')}>{formatDateTime(s.startAt)} → {formatDateTime(s.endedAt ?? s.endAt)}</Row>
          <Row label={t('sess.duration')}>{t('sess.minutes', { n: s.durationMinutes })}{s.endedEarly && ` · ${t('sess.endedEarly')}`}</Row>
          <Row label={t('sess.amount')}>{formatMoney(s.amount)}</Row>
          <Row label={t('sess.payment')}><PaymentChip status={s.paymentStatus} /></Row>
          {s.device && <Row label={t('table.device')}>{s.device.code}</Row>}
          {s.failureReason && <Row label="Sabab">{s.failureReason}</Row>}
        </dl>
        <div className="mt-4 flex flex-wrap gap-2">
          {running && perms.includes('sessions.stop') && (
            <Button variant="danger" loading={stop.isPending} onClick={() => window.confirm(t('sess.confirmStop')) && stop.mutate()}>{t('sess.stop')}</Button>
          )}
          {payable && perms.includes('sessions.mark_payment') && (['PAID', 'UNPAID', 'WAIVED'] as PaymentStatusValue[]).filter((p) => p !== s.paymentStatus).map((p) => (
            <Button key={p} variant="secondary" loading={pay.isPending && pay.variables === p} onClick={() => pay.mutate(p)}>
              {tDynamic('pay', p)}
            </Button>
          ))}
        </div>
        {(stop.isError || pay.isError) && <div className="mt-3"><ErrorBanner error={stop.error ?? pay.error} /></div>}
      </Card>
      <Card title={t('sess.history')}>
        <ol className="space-y-1 text-sm">
          {s.events?.map((e, i) => (
            <li key={i} className="flex justify-between gap-2">
              <span>{e.from ? `${tDynamic('sstatus', e.from)} → ` : ''}<b>{tDynamic('sstatus', e.to)}</b>{e.reason ? ` · ${e.reason}` : ''}</span>
              <time className="text-xs text-slate-500">{formatDateTime(e.at)}</time>
            </li>
          ))}
        </ol>
      </Card>
    </div>
  );
}

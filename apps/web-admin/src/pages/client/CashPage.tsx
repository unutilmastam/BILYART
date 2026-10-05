import { useQuery } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { useMe } from '../../auth/useMe';
import { Button } from '../../components/Button';
import { Card, Stat } from '../../components/Card';
import { Empty, ErrorBanner, Spinner, SuccessBanner } from '../../components/Feedback';
import { TextField } from '../../components/Field';
import { useClientMutation } from '../../features/client/api';
import { t, tDynamic } from '../../i18n';
import { api } from '../../lib/api';
import { formatDateTime, formatMoney } from '../../lib/format';
import type { CashNoteStatus, CashOverview } from '../../types/api';

const useCash = () => useQuery({ queryKey: ['client', 'cash'], queryFn: () => api<CashOverview>('/admin/cash'), refetchInterval: 15_000 });

const noteTone: Record<CashNoteStatus, string> = {
  CREDITED: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  UNASSIGNED: 'bg-amber-50 text-amber-800 ring-amber-600/25',
  RESOLVED: 'bg-slate-100 text-slate-600 ring-slate-500/20',
};

function Collect({ deviceId, expected }: { deviceId: string; expected: number }) {
  const [counted, setCounted] = useState('');
  const [comment, setComment] = useState('');
  const m = useClientMutation<{ deviceId: string; countedAmount: number; comment?: string }>('/admin/cash/collections', 'POST');
  const value = Number(counted);
  const valid = counted !== '' && Number.isInteger(value) && value >= 0;
  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (!valid || !window.confirm(t('cash.confirmCollect', { amount: formatMoney(value) }))) return;
    m.mutate({ deviceId, countedAmount: value, comment: comment.trim() || undefined }, { onSuccess: () => { setCounted(''); setComment(''); } });
  };
  return (
    <form onSubmit={submit} className="mt-4 space-y-3 rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200" noValidate>
      <p className="text-sm font-semibold text-slate-800">{t('cash.collectTitle')}</p>
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField label={t('cash.counted')} type="number" inputMode="numeric" min={0} step={1000} value={counted} placeholder={String(expected)} onChange={(e) => setCounted(e.target.value)} />
        <TextField label={t('cash.comment')} value={comment} maxLength={300} onChange={(e) => setComment(e.target.value)} />
      </div>
      {m.isError && <ErrorBanner error={m.error} />}
      {m.isSuccess && <SuccessBanner>{t('actions.done')}</SuccessBanner>}
      <Button type="submit" loading={m.isPending} disabled={!valid}>{t('cash.collect')}</Button>
    </form>
  );
}

function ResolveButton({ id }: { id: string }) {
  const m = useClientMutation<{ comment: string }>(`/admin/cash/notes/${id}/resolve`, 'POST');
  return (
    <>
      <Button
        variant="secondary"
        loading={m.isPending}
        onClick={() => {
          const comment = window.prompt(t('cash.resolvePrompt'))?.trim();
          if (comment && comment.length >= 3) m.mutate({ comment });
        }}
      >
        {t('cash.resolve')}
      </Button>
      {m.isError && <ErrorBanner error={m.error} />}
    </>
  );
}

/** Client Admin → Kassa: what each bill acceptor took, what is in the box, money that needs a decision. */
export function CashPage() {
  const me = useMe();
  const canManage = !!me.data?.permissions.includes('cash.manage');
  const q = useCash();
  if (q.isPending) return <Spinner />;
  if (q.isError) return <ErrorBanner error={q.error} onRetry={() => q.refetch()} />;
  const { boxes, notes, collections } = q.data;

  return (
    <div className="space-y-4">
      {boxes.length === 0 ? (
        <Card title={t('cash.title')}>
          <p className="text-sm text-slate-600">{t('cash.noBoxes')}</p>
        </Card>
      ) : (
        boxes.map((b) => (
          <Card
            key={b.branch.id}
            title={b.branch.name}
            description={b.device ? `${b.device.code} · ${b.device.online ? t('cash.online') : t('cash.offline')}${b.device.accepting ? ` · ${t('cash.accepting')}` : ''}` : t('cash.noDevice')}
          >
            <div className="grid gap-3 sm:grid-cols-3">
              <Stat label={t('cash.inBox')} value={formatMoney(b.uncollected.amount)} tone="brand" />
              <Stat label={t('cash.today')} value={formatMoney(b.today.amount)} />
              <Stat label={t('cash.unassigned')} value={formatMoney(b.unassigned.amount)} tone={b.unassigned.count > 0 ? 'warn' : 'default'} />
            </div>
            <p className="mt-2 text-xs text-slate-500">{t('cash.notesN', { n: b.uncollected.count })}</p>
            {canManage && b.device && <Collect deviceId={b.device.id} expected={b.uncollected.amount} />}
          </Card>
        ))
      )}

      <Card title={t('cash.notes')}>
        {notes.length === 0 ? <Empty /> : (
          <ul className="divide-y divide-slate-100">
            {notes.map((n) => (
              <li key={n.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                <span className="min-w-0">
                  <span className="font-semibold tabular">{formatMoney(n.nominal)}</span>
                  <span className="block text-xs text-slate-500">
                    {formatDateTime(n.receivedAt)} · {n.branch ?? '—'}{n.table ? ` · ${n.table}` : ''}
                  </span>
                  {n.resolveComment && <span className="block text-xs text-slate-600">{n.resolveComment}</span>}
                </span>
                <span className="flex items-center gap-2">
                  <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset ${noteTone[n.status]}`}>{tDynamic('cashstatus', n.status)}</span>
                  {canManage && n.status === 'UNASSIGNED' && <ResolveButton id={n.id} />}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card title={t('cash.collections')}>
        {collections.length === 0 ? <Empty /> : (
          <ul className="divide-y divide-slate-100">
            {collections.map((c) => (
              <li key={c.id} className="flex flex-wrap items-start justify-between gap-2 py-2.5 text-sm">
                <span className="min-w-0">
                  <span className="font-semibold tabular">{formatMoney(c.counted)}</span>
                  <span className="block text-xs text-slate-500">
                    {formatDateTime(c.createdAt)} · {c.branch ?? '—'} · {c.collectedBy ?? '—'} · {t('cash.notesN', { n: c.notesCount })}
                  </span>
                  {c.comment && <span className="block text-xs text-slate-600">{c.comment}</span>}
                </span>
                {c.counted !== c.expected && (
                  <span className="rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-red-200">
                    {t('cash.difference')}: {formatMoney(c.counted - c.expected)}
                  </span>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}

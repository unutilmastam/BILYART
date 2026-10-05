import { useEffect, useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router';
import { useMe } from '../../auth/useMe';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Empty, ErrorBanner, Spinner, SuccessBanner } from '../../components/Feedback';
import { SelectField, TextField } from '../../components/Field';
import { useBranch, useClientMutation, useClosedDays, useWorkingHours } from '../../features/client/api';
import { t } from '../../i18n';
import type { MessageKey } from '../../i18n/uz';
import { ApiError } from '../../lib/api';
import { formatDate } from '../../lib/format';
import type { Branch, PaymentMode, WorkingDay } from '../../types/api';

function EditBranch({ branch }: { branch: Branch }) {
  const [form, setForm] = useState({ name: branch.name, address: branch.address ?? '', phone: branch.phone ?? '', reportTime: branch.reportTime, paymentMode: branch.paymentMode });
  const m = useClientMutation<Partial<Branch>>(`/admin/branches/${branch.id}`, 'PATCH');
  const err = (f: string) => (m.error instanceof ApiError ? m.error.fieldError(f) : undefined);
  return (
    <form onSubmit={(e) => { e.preventDefault(); m.mutate({ ...form, address: form.address || null, phone: form.phone || null }); }} className="space-y-3" noValidate>
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField label={t('branch.name')} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={err('name')} />
        <TextField label={t('branch.address')} value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} />
        <TextField label={t('branch.phone')} type="tel" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
        <TextField label={t('branch.reportTime')} type="time" value={form.reportTime} onChange={(e) => setForm({ ...form, reportTime: e.target.value })} error={err('reportTime')} />
        <SelectField
          label={t('branch.paymentMode')}
          value={form.paymentMode}
          onChange={(e) => setForm({ ...form, paymentMode: e.target.value as PaymentMode })}
          options={[{ value: 'CASHIER', label: t('paymode.CASHIER') }, { value: 'BILL_ACCEPTOR', label: t('paymode.BILL_ACCEPTOR') }]}
          hint={form.paymentMode === 'BILL_ACCEPTOR' ? t('branch.paymentModeHint') : undefined}
        />
      </div>
      {m.isError && <ErrorBanner error={m.error} />}
      {m.isSuccess && <SuccessBanner>{t('settings.saved')}</SuccessBanner>}
      <div className="flex flex-wrap gap-2">
        <Button type="submit" loading={m.isPending}>{t('common.save')}</Button>
        <ToggleActive branch={branch} />
      </div>
    </form>
  );
}

function ToggleActive({ branch }: { branch: Branch }) {
  const m = useClientMutation<{ isActive: boolean }>(`/admin/branches/${branch.id}`, 'PATCH');
  return (
    <>
      <Button type="button" variant={branch.isActive ? 'danger' : 'secondary'} loading={m.isPending} onClick={() => m.mutate({ isActive: !branch.isActive })}>
        {branch.isActive ? t('branch.disable') : t('branch.enable')}
      </Button>
      {m.isError && <ErrorBanner error={m.error} />}
    </>
  );
}

function HoursEditor({ branchId, canEdit }: { branchId: string; canEdit: boolean }) {
  const q = useWorkingHours(branchId);
  const [days, setDays] = useState<WorkingDay[]>([]);
  const m = useClientMutation<{ days: WorkingDay[] }>(`/admin/branches/${branchId}/working-hours`, 'PUT');
  useEffect(() => { if (q.data) setDays(q.data); }, [q.data]);

  if (q.isPending) return <Spinner />;
  if (q.isError) return <ErrorBanner error={q.error} />;
  const update = (i: number, patch: Partial<WorkingDay>) => setDays((d) => d.map((x, j) => (j === i ? { ...x, ...patch } : x)));

  return (
    <form onSubmit={(e: FormEvent) => { e.preventDefault(); m.mutate({ days }); }} className="space-y-2" noValidate>
      <p className="text-xs text-slate-500">{t('branch.hoursHint')}</p>
      {days.map((d, i) => (
        <div key={d.weekday} className="grid grid-cols-[6rem_auto_1fr_1fr] items-center gap-2 text-sm">
          <span className="font-medium">{t(`weekday.${d.weekday}` as MessageKey)}</span>
          <label className="flex items-center gap-1 text-xs">
            <input type="checkbox" className="size-5" disabled={!canEdit} checked={d.isClosed} onChange={(e) => update(i, { isClosed: e.target.checked, opensAt: e.target.checked ? null : '10:00', closesAt: e.target.checked ? null : '02:00' })} />
            {t('branch.dayClosed')}
          </label>
          <input aria-label={t('branch.opens')} type="time" disabled={!canEdit || d.isClosed} value={d.opensAt ?? ''} onChange={(e) => update(i, { opensAt: e.target.value })} className="min-h-11 rounded-xl border border-slate-300 bg-white px-3 shadow-sm outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15" />
          <input aria-label={t('branch.closes')} type="time" disabled={!canEdit || d.isClosed} value={d.closesAt ?? ''} onChange={(e) => update(i, { closesAt: e.target.value })} className="min-h-11 rounded-xl border border-slate-300 bg-white px-3 shadow-sm outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15" />
        </div>
      ))}
      {m.isError && <ErrorBanner error={m.error} />}
      {m.isSuccess && <SuccessBanner>{t('settings.saved')}</SuccessBanner>}
      {canEdit && <Button type="submit" loading={m.isPending}>{t('common.save')}</Button>}
    </form>
  );
}

function ClosedDays({ branchId, canEdit }: { branchId: string; canEdit: boolean }) {
  const q = useClosedDays(branchId);
  const [date, setDate] = useState('');
  const [reason, setReason] = useState('');
  const add = useClientMutation<{ date: string; reason?: string }>(`/admin/branches/${branchId}/closed-days`, 'POST');
  return (
    <div className="space-y-3">
      {q.data && q.data.length === 0 && <Empty />}
      <ul className="divide-y divide-slate-100 text-sm">
        {q.data?.map((d) => <ClosedDayRow key={d.id} branchId={branchId} id={d.id} label={`${formatDate(`${d.date}T12:00:00Z`)}${d.reason ? ` · ${d.reason}` : ''}`} canEdit={canEdit} />)}
      </ul>
      {canEdit && (
        <form onSubmit={(e) => { e.preventDefault(); add.mutate({ date, reason: reason || undefined }, { onSuccess: () => { setDate(''); setReason(''); } }); }} className="grid gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-end" noValidate>
          <TextField label={t('branch.closedDay')} type="date" value={date} onChange={(e) => setDate(e.target.value)} />
          <TextField label={t('actions.reason')} value={reason} onChange={(e) => setReason(e.target.value)} />
          <Button type="submit" disabled={!date} loading={add.isPending}>{t('branch.addClosedDay')}</Button>
        </form>
      )}
    </div>
  );
}

function ClosedDayRow({ branchId, id, label, canEdit }: { branchId: string; id: string; label: string; canEdit: boolean }) {
  const del = useClientMutation(`/admin/branches/${branchId}/closed-days/${id}`, 'DELETE');
  return (
    <li className="flex items-center justify-between py-2">
      <span>{label}</span>
      {canEdit && <Button variant="ghost" loading={del.isPending} onClick={() => del.mutate()}>×</Button>}
    </li>
  );
}

export function BranchDetailPage() {
  const { id = '' } = useParams();
  const me = useMe();
  const q = useBranch(id);
  const perms = me.data?.permissions ?? [];

  if (q.isPending) return <Spinner />;
  if (q.isError) return <ErrorBanner error={q.error} onRetry={() => q.refetch()} />;

  return (
    <div className="space-y-4">
      <Link to="/client/branches" className="text-sm text-brand-700">‹ {t('common.back')}</Link>
      <Card title={q.data.name}>
        {perms.includes('branches.manage') ? <EditBranch key={q.data.id + q.data.isActive} branch={q.data} /> : <p className="text-sm text-slate-600">{q.data.address}</p>}
      </Card>
      <Card title={t('branch.hours')}><HoursEditor branchId={id} canEdit={perms.includes('working_hours.manage')} /></Card>
      <Card title={t('branch.closedDays')}><ClosedDays branchId={id} canEdit={perms.includes('working_hours.manage')} /></Card>
    </div>
  );
}

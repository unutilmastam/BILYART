import { useState, type FormEvent } from 'react';
import { useMe } from '../../auth/useMe';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Empty, ErrorBanner, Spinner } from '../../components/Feedback';
import { SelectField, TextField } from '../../components/Field';
import { useBranches, useClientMutation, useDevices, usePlans, useTables } from '../../features/client/api';
import { t } from '../../i18n';
import { ApiError } from '../../lib/api';
import { formatMoney } from '../../lib/format';
import type { DeviceInfo, PricingPlan, Table } from '../../types/api';

function planOptions(plans: PricingPlan[] | undefined, branchId: string) {
  return [
    { value: '', label: t('table.noPlan') },
    ...(plans ?? []).filter((p) => p.isActive && (p.branchId === null || p.branchId === branchId)).map((p) => ({ value: p.id, label: `${p.name} — ${formatMoney(p.pricePerHour)}/soat` })),
  ];
}

/** "deviceId:channel" options: free relay channels of the branch's devices plus the table's current one. */
function wiringOptions(devices: DeviceInfo[] | undefined, table: Table) {
  const options = [{ value: '', label: t('table.noDevice') }];
  for (const d of devices ?? []) {
    if (d.branch?.id !== table.branchId) continue;
    for (const c of d.channels ?? []) {
      if (c.table && c.table.id !== table.id) continue;
      options.push({ value: `${d.id}:${c.channel}`, label: `${d.code} · ${t('dev.channel', { n: c.channel })}` });
    }
  }
  return options;
}

function CreateTable({ branchId, plans }: { branchId: string; plans?: PricingPlan[] }) {
  const [number, setNumber] = useState('');
  const [plan, setPlan] = useState('');
  const m = useClientMutation<{ branchId: string; number: number; pricingPlanId: string | null }>('/admin/tables', 'POST');
  const err = (f: string) => (m.error instanceof ApiError ? m.error.fieldError(f) : undefined);
  const submit = (e: FormEvent) => {
    e.preventDefault();
    m.mutate({ branchId, number: Number(number), pricingPlanId: plan || null }, { onSuccess: () => setNumber('') });
  };
  return (
    <form onSubmit={submit} className="grid gap-3 sm:grid-cols-[8rem_1fr_auto] sm:items-end" noValidate>
      <TextField label={t('table.number')} type="number" inputMode="numeric" min={1} value={number} onChange={(e) => setNumber(e.target.value)} error={err('number')} />
      <SelectField label={t('table.plan')} value={plan} onChange={(e) => setPlan(e.target.value)} options={planOptions(plans, branchId)} error={err('pricingPlanId')} />
      <Button type="submit" loading={m.isPending} disabled={!number}>{t('table.new')}</Button>
      {m.isError && !err('number') && !err('pricingPlanId') && <div className="sm:col-span-3"><ErrorBanner error={m.error} /></div>}
    </form>
  );
}

function TableRow({ table, plans, devices, canManage }: { table: Table; plans?: PricingPlan[]; devices?: DeviceInfo[]; canManage: boolean }) {
  const m = useClientMutation<{ pricingPlanId?: string | null; isActive?: boolean; deviceId?: string | null; deviceChannel?: number | null }>(`/admin/tables/${table.id}`, 'PATCH');
  const wire = (value: string) => {
    const [deviceId, channel] = value.split(':');
    m.mutate(deviceId ? { deviceId, deviceChannel: Number(channel) } : { deviceId: null });
  };
  return (
    <li className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
      <div className="min-w-0">
        <p className={`font-medium ${table.isActive ? '' : 'text-slate-400 line-through'}`}>{table.name}</p>
        <p className="text-xs text-slate-500">
          {t('table.device')}:{' '}
          {table.device ? (
            <span className={table.device.online ? 'text-emerald-700' : 'text-red-700'}>
              {table.device.code} · {t('dev.channel', { n: table.device.channel })} · {table.device.online ? t('table.online') : t('table.offline')}
            </span>
          ) : t('table.noDevice')}
        </p>
      </div>
      {canManage ? (
        <div className="flex flex-wrap items-center gap-2">
          <select
            aria-label={t('table.plan')}
            className="min-h-11 rounded-xl border border-slate-300 bg-white px-3 shadow-sm outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 text-sm"
            value={table.pricingPlan?.id ?? ''}
            onChange={(e) => m.mutate({ pricingPlanId: e.target.value || null })}
          >
            {planOptions(plans, table.branchId).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
          {devices && (
            <select
              aria-label={t('table.wiring')}
              className="min-h-11 rounded-xl border border-slate-300 bg-white px-3 shadow-sm outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 text-sm"
              value={table.device ? `${table.device.id}:${table.device.channel}` : ''}
              onChange={(e) => wire(e.target.value)}
            >
              {wiringOptions(devices, table).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
          )}
          <Button variant="secondary" loading={m.isPending} onClick={() => m.mutate({ isActive: !table.isActive })}>
            {table.isActive ? t('branch.disable') : t('branch.enable')}
          </Button>
          {m.isError && <ErrorBanner error={m.error} />}
        </div>
      ) : (
        <span className="text-sm">{table.pricingPlan ? `${formatMoney(table.pricingPlan.pricePerHour)}/soat` : t('table.noPlan')}</span>
      )}
    </li>
  );
}

export function TablesPage() {
  const me = useMe();
  const branches = useBranches();
  const [branchId, setBranchId] = useState('');
  const selected = branchId || branches.data?.[0]?.id || '';
  const tables = useTables(selected || undefined);
  const canManage = !!me.data?.permissions.includes('tables.manage');
  const plans = usePlans(!!me.data?.permissions.includes('pricing.manage'));
  const canWire = canManage && !!me.data?.permissions.includes('devices.manage');
  const devices = useDevices(canWire);

  if (branches.isPending) return <Spinner />;
  if (branches.isError) return <ErrorBanner error={branches.error} />;
  if (branches.data.length === 0) return <Card><Empty /></Card>;

  return (
    <div className="space-y-4">
      <Card>
        <SelectField label={t('table.branch')} value={selected} onChange={(e) => setBranchId(e.target.value)} options={branches.data.map((b) => ({ value: b.id, label: b.name }))} />
      </Card>
      {canManage && <Card title={t('table.new')}><CreateTable branchId={selected} plans={plans.data} /></Card>}
      <Card title={t('nav.tables')}>
        {tables.isPending ? <Spinner /> : tables.isError ? <ErrorBanner error={tables.error} /> : tables.data.length === 0 ? <Empty /> : (
          <ul className="divide-y divide-slate-100">
            {tables.data.map((tb) => <TableRow key={tb.id} table={tb} plans={plans.data} devices={canWire ? devices.data : undefined} canManage={canManage} />)}
          </ul>
        )}
      </Card>
    </div>
  );
}

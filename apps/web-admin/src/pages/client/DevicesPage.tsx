import { useQuery } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { useMe } from '../../auth/useMe';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Empty, ErrorBanner, Spinner, SuccessBanner } from '../../components/Feedback';
import { SelectField, TextField } from '../../components/Field';
import { useBranches, useClientMutation, useDevices } from '../../features/client/api';
import { t, tDynamic } from '../../i18n';
import { api, ApiError } from '../../lib/api';
import { formatDateTime } from '../../lib/format';
import type { DeviceInfo, TabletInfo } from '../../types/api';
import { KioskQr } from './KioskQr';

const useTablets = () => useQuery({ queryKey: ['client', 'tablets'], queryFn: async () => (await api<{ data: TabletInfo[] }>('/admin/tablets')).data, refetchInterval: 30_000 });

function OnlineDot({ online }: { online: boolean }) {
  return <span className={`inline-block size-2.5 rounded-full ${online ? 'bg-emerald-500' : 'bg-red-500'}`} aria-label={online ? t('table.online') : t('table.offline')} />;
}

/** The device is paired to a branch; tables are wired to its relay channels on the Tables page. */
function PairEsp() {
  const branches = useBranches();
  const [code, setCode] = useState('');
  const [branchId, setBranchId] = useState('');
  const m = useClientMutation<{ code: string; branchId: string }>('/admin/devices/pair', 'POST');
  const selected = branchId || branches.data?.find((b) => b.isActive)?.id || '';
  const err = (f: string) => (m.error instanceof ApiError ? m.error.fieldError(f) : undefined);
  const submit = (e: FormEvent) => { e.preventDefault(); m.mutate({ code, branchId: selected }, { onSuccess: () => setCode('') }); };
  return (
    <form onSubmit={submit} className="space-y-3" noValidate>
      <p className="text-xs text-slate-500">{t('dev.espHint')}</p>
      <div className="grid gap-3 sm:grid-cols-[10rem_1fr_auto] sm:items-end">
        <TextField label={t('dev.code')} inputMode="numeric" maxLength={6} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} error={err('code')} />
        <SelectField label={t('table.branch')} value={selected} onChange={(e) => setBranchId(e.target.value)} options={(branches.data ?? []).filter((b) => b.isActive).map((b) => ({ value: b.id, label: b.name }))} error={err('branchId')} />
        <Button type="submit" disabled={code.length !== 6 || !selected} loading={m.isPending}>{t('dev.pair')}</Button>
      </div>
      {m.isError && !err('code') && !err('branchId') && <ErrorBanner error={m.error} />}
      {m.isSuccess && <SuccessBanner>{t('actions.done')}</SuccessBanner>}
    </form>
  );
}

const lampTone: Record<string, string> = { ON: 'bg-emerald-100 text-emerald-800', WARNING: 'bg-amber-100 text-amber-800', OFF: 'bg-slate-100 text-slate-600' };

function Channels({ d }: { d: DeviceInfo }) {
  return (
    <ul className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4" aria-label={t('dev.channels')}>
      {(d.channels ?? []).map((c) => (
        <li key={c.channel} className="rounded-xl border border-slate-200 bg-slate-50/60 px-2.5 py-2 text-xs">
          <p className="text-slate-500">{t('dev.channel', { n: c.channel })}</p>
          <p className="flex items-center justify-between gap-1 font-medium">
            <span className="truncate">{c.table?.name ?? <span className="text-slate-400">{t('dev.channelFree')}</span>}</span>
            {c.state && <span className={`rounded px-1.5 py-0.5 ${lampTone[c.state] ?? ''}`}>{tDynamic('dev.lamp', c.state)}</span>}
          </p>
        </li>
      ))}
    </ul>
  );
}

function MoveDevice({ d }: { d: DeviceInfo }) {
  const branches = useBranches();
  const m = useClientMutation<{ branchId: string }>(`/admin/devices/${d.id}`, 'PATCH');
  const others = (branches.data ?? []).filter((b) => b.isActive && b.id !== d.branch?.id);
  if (others.length === 0) return null;
  const wired = (d.channels ?? []).some((c) => c.table);
  return (
    <div className="mt-2">
      <select
        aria-label={t('dev.move')}
        title={wired ? t('dev.moveHint') : undefined}
        disabled={wired || m.isPending}
        className="min-h-11 rounded-xl border border-slate-300 bg-white px-3 shadow-sm outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 text-sm disabled:opacity-50"
        value=""
        onChange={(e) => e.target.value && m.mutate({ branchId: e.target.value })}
      >
        <option value="">{t('dev.move')}…</option>
        {others.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
      </select>
      {wired && <p className="mt-1 text-xs text-slate-500">{t('dev.moveHint')}</p>}
      {m.isError && <ErrorBanner error={m.error} />}
    </div>
  );
}

function PairTablet() {
  const branches = useBranches();
  const [code, setCode] = useState('');
  const [branchId, setBranchId] = useState('');
  const [name, setName] = useState('');
  const m = useClientMutation<{ code: string; branchId: string; name?: string }>('/admin/tablets/pair', 'POST');
  const selected = branchId || branches.data?.[0]?.id || '';
  const submit = (e: FormEvent) => { e.preventDefault(); m.mutate({ code, branchId: selected, name: name || undefined }, { onSuccess: () => { setCode(''); setName(''); } }); };
  return (
    <form onSubmit={submit} className="space-y-3" noValidate>
      <p className="text-xs text-slate-500">{t('dev.tabletHint')}</p>
      <div className="grid gap-3 sm:grid-cols-[10rem_1fr_1fr_auto] sm:items-end">
        <TextField label={t('dev.code')} inputMode="numeric" maxLength={6} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} />
        <SelectField label={t('table.branch')} value={selected} onChange={(e) => setBranchId(e.target.value)} options={(branches.data ?? []).filter((b) => b.isActive).map((b) => ({ value: b.id, label: b.name }))} />
        <TextField label={t('dev.tabletName')} value={name} onChange={(e) => setName(e.target.value)} />
        <Button type="submit" disabled={code.length !== 6 || !selected} loading={m.isPending}>{t('dev.pair')}</Button>
      </div>
      {m.isError && <ErrorBanner error={m.error} />}
      {m.isSuccess && <SuccessBanner>{t('actions.done')}</SuccessBanner>}
    </form>
  );
}

function DeviceRow({ d, canManage }: { d: DeviceInfo; canManage: boolean }) {
  const unpair = useClientMutation(`/admin/devices/${d.id}/unpair`, 'POST');
  return (
    <li className="py-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <p className="flex items-center gap-2 font-medium">
            <OnlineDot online={d.online} /> {d.code} · {d.branch?.name ?? '—'}
            {d.kind === 'CASH' && <span className="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800 ring-1 ring-amber-200">{t('dev.cashBox')}</span>}
          </p>
          <p className="text-xs text-slate-500">
            {t('dev.lastSeen')}: {formatDateTime(d.lastSeenAt)} · {t('dev.fw')}: {d.firmwareVersion ?? '—'}
            {d.rssi !== null && ` · ${d.rssi} dBm`}
          </p>
        </div>
        {canManage && <Button variant="secondary" loading={unpair.isPending} onClick={() => window.confirm(t('dev.confirmUnpair')) && unpair.mutate()}>{t('dev.unpair')}</Button>}
      </div>
      {d.kind === 'CASH' ? <p className="mt-1 text-xs text-slate-500">{t('dev.cashHint')}</p> : <Channels d={d} />}
      {canManage && <MoveDevice d={d} />}
      {unpair.isError && <ErrorBanner error={unpair.error} />}
    </li>
  );
}

function TabletRow({ tb, canManage }: { tb: TabletInfo; canManage: boolean }) {
  const revoke = useClientMutation(`/admin/tablets/${tb.id}/revoke`, 'POST');
  return (
    <li className="flex flex-wrap items-center justify-between gap-2 py-3">
      <div>
        <p className="flex items-center gap-2 font-medium"><OnlineDot online={tb.online} /> {tb.name} <span className="text-xs text-slate-500">{tb.code}</span></p>
        <p className="text-xs text-slate-500">{tb.branch?.name} · {t('dev.lastSeen')}: {formatDateTime(tb.lastSeenAt)} · {tb.deviceModel ?? ''} {tb.appVersion ?? ''}</p>
      </div>
      {canManage && <Button variant="secondary" loading={revoke.isPending} onClick={() => revoke.mutate()}>{t('dev.revoke')}</Button>}
    </li>
  );
}

export function DevicesPage() {
  const me = useMe();
  const canManage = !!me.data?.permissions.includes('devices.manage');
  const devices = useDevices();
  const tablets = useTablets();

  return (
    <div className="space-y-4">
      {canManage && <Card title={t('dev.pairEsp')}><PairEsp /></Card>}
      <Card title={t('dev.esp32')}>
        {devices.isPending ? <Spinner /> : devices.isError ? <ErrorBanner error={devices.error} /> : devices.data.length === 0 ? <Empty /> : (
          <ul className="divide-y divide-slate-100">{devices.data.map((d) => <DeviceRow key={d.id} d={d} canManage={canManage} />)}</ul>
        )}
      </Card>
      {canManage && <Card title={t('dev.pairTablet')}><PairTablet /></Card>}
      {canManage && <Card title={t('kiosk.title')} description={t('kiosk.intro')}><KioskQr /></Card>}
      <Card title={t('dev.tablets')}>
        {tablets.isPending ? <Spinner /> : tablets.isError ? <ErrorBanner error={tablets.error} /> : tablets.data.length === 0 ? <Empty /> : (
          <ul className="divide-y divide-slate-100">{tablets.data.map((tb) => <TabletRow key={tb.id} tb={tb} canManage={canManage} />)}</ul>
        )}
      </Card>
    </div>
  );
}

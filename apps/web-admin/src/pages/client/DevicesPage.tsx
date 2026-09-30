import { useQuery } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { useMe } from '../../auth/useMe';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Empty, ErrorBanner, Spinner, SuccessBanner } from '../../components/Feedback';
import { SelectField, TextField } from '../../components/Field';
import { useBranches, useClientMutation, useTables } from '../../features/client/api';
import { t } from '../../i18n';
import { api, ApiError } from '../../lib/api';
import { formatDateTime } from '../../lib/format';
import type { DeviceInfo, TabletInfo } from '../../types/api';

const useDevices = () => useQuery({ queryKey: ['client', 'devices'], queryFn: async () => (await api<{ data: DeviceInfo[] }>('/admin/devices')).data, refetchInterval: 10_000 });
const useTablets = () => useQuery({ queryKey: ['client', 'tablets'], queryFn: async () => (await api<{ data: TabletInfo[] }>('/admin/tablets')).data, refetchInterval: 30_000 });

function OnlineDot({ online }: { online: boolean }) {
  return <span className={`inline-block size-2.5 rounded-full ${online ? 'bg-emerald-500' : 'bg-red-500'}`} aria-label={online ? t('table.online') : t('table.offline')} />;
}

function PairEsp() {
  const tables = useTables();
  const [code, setCode] = useState('');
  const [tableId, setTableId] = useState('');
  const m = useClientMutation<{ code: string; tableId: string }>('/admin/devices/pair', 'POST');
  const free = (tables.data ?? []).filter((tb) => tb.isActive && !tb.device);
  const err = (f: string) => (m.error instanceof ApiError ? m.error.fieldError(f) : undefined);
  const submit = (e: FormEvent) => { e.preventDefault(); m.mutate({ code, tableId: tableId || free[0]?.id || '' }, { onSuccess: () => setCode('') }); };
  return (
    <form onSubmit={submit} className="space-y-3" noValidate>
      <p className="text-xs text-slate-500">{t('dev.espHint')}</p>
      <div className="grid gap-3 sm:grid-cols-[10rem_1fr_auto] sm:items-end">
        <TextField label={t('dev.code')} inputMode="numeric" maxLength={6} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} error={err('code')} />
        <SelectField label={t('sess.table')} value={tableId || free[0]?.id || ''} onChange={(e) => setTableId(e.target.value)} options={free.map((tb) => ({ value: tb.id, label: tb.name }))} error={err('tableId')} />
        <Button type="submit" disabled={code.length !== 6 || free.length === 0} loading={m.isPending}>{t('dev.pair')}</Button>
      </div>
      {m.isError && !err('code') && !err('tableId') && <ErrorBanner error={m.error} />}
      {m.isSuccess && <SuccessBanner>{t('actions.done')}</SuccessBanner>}
    </form>
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
    <li className="flex flex-wrap items-center justify-between gap-2 py-3">
      <div>
        <p className="flex items-center gap-2 font-medium"><OnlineDot online={d.online} /> {d.code} · {d.table?.name ?? '—'}</p>
        <p className="text-xs text-slate-500">
          {d.branch?.name} · {t('dev.lastSeen')}: {formatDateTime(d.lastSeenAt)} · {t('dev.fw')}: {d.firmwareVersion ?? '—'} · {t('dev.light')}: {d.state ?? '—'}
          {d.rssi !== null && ` · ${d.rssi} dBm`}
        </p>
      </div>
      {canManage && <Button variant="secondary" loading={unpair.isPending} onClick={() => window.confirm(t('dev.confirmUnpair')) && unpair.mutate()}>{t('dev.unpair')}</Button>}
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
      <Card title={t('dev.tablets')}>
        {tablets.isPending ? <Spinner /> : tablets.isError ? <ErrorBanner error={tablets.error} /> : tablets.data.length === 0 ? <Empty /> : (
          <ul className="divide-y divide-slate-100">{tablets.data.map((tb) => <TabletRow key={tb.id} tb={tb} canManage={canManage} />)}</ul>
        )}
      </Card>
    </div>
  );
}

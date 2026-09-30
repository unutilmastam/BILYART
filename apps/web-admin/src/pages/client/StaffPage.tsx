import { useState, type FormEvent } from 'react';
import { useMe } from '../../auth/useMe';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Empty, ErrorBanner, Spinner, SuccessBanner } from '../../components/Feedback';
import { SelectField, TextField } from '../../components/Field';
import { useBranches, useClientMutation, useStaff } from '../../features/client/api';
import { t, tDynamic } from '../../i18n';
import { ApiError } from '../../lib/api';
import type { Role, StaffUser } from '../../types/api';

function roleOptions(actor: Role | undefined): { value: string; label: string }[] {
  const roles: Role[] = actor === 'CLIENT_OWNER' ? ['CLIENT_OPERATOR', 'CLIENT_MANAGER', 'CLIENT_OWNER'] : ['CLIENT_OPERATOR', 'CLIENT_MANAGER'];
  return roles.map((r) => ({ value: r, label: tDynamic('role', r) }));
}

function BranchPicker({ value, onChange }: { value: string[]; onChange: (v: string[]) => void }) {
  const branches = useBranches();
  return (
    <fieldset className="space-y-1">
      <legend className="text-sm font-medium text-slate-700">{t('staff.branches')}</legend>
      <div className="flex flex-wrap gap-3">
        {branches.data?.map((b) => (
          <label key={b.id} className="flex items-center gap-2 text-sm">
            <input type="checkbox" className="size-5" checked={value.includes(b.id)} onChange={(e) => onChange(e.target.checked ? [...value, b.id] : value.filter((x) => x !== b.id))} />
            {b.name}
          </label>
        ))}
      </div>
    </fieldset>
  );
}

function StaffForm({ user, onDone }: { user?: StaffUser; onDone?: () => void }) {
  const me = useMe();
  const m = useClientMutation<Record<string, unknown>>(user ? `/admin/users/${user.id}` : '/admin/users', user ? 'PATCH' : 'POST');
  const [form, setForm] = useState({ name: user?.name ?? '', login: '', password: '', role: (user?.role ?? 'CLIENT_OPERATOR') as Role, branchIds: user?.branchIds ?? [] });
  const err = (f: string) => (m.error instanceof ApiError ? m.error.fieldError(f) : undefined);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    const body: Record<string, unknown> = { name: form.name, role: form.role, branchIds: form.branchIds };
    if (!user) body.login = form.login;
    if (form.password) body.password = form.password;
    m.mutate(body, { onSuccess: () => { if (!user) setForm({ ...form, name: '', login: '', password: '', branchIds: [] }); onDone?.(); } });
  };

  return (
    <form onSubmit={submit} className="space-y-3" noValidate>
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField label={t('staff.name')} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={err('name')} />
        {!user && <TextField label={t('staff.login')} autoCapitalize="none" value={form.login} onChange={(e) => setForm({ ...form, login: e.target.value })} error={err('login')} />}
        <TextField label={user ? t('staff.newPassword') : t('staff.password')} type="password" autoComplete="new-password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} error={err('password')} />
        <SelectField label={t('staff.role')} value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value as Role })} options={roleOptions(me.data?.user.role)} error={err('role')} />
      </div>
      {form.role !== 'CLIENT_OWNER' && <BranchPicker value={form.branchIds} onChange={(branchIds) => setForm({ ...form, branchIds })} />}
      {m.isError && <ErrorBanner error={m.error} />}
      {m.isSuccess && <SuccessBanner>{t('actions.done')}</SuccessBanner>}
      <Button type="submit" loading={m.isPending}>{user ? t('common.save') : t('staff.new')}</Button>
    </form>
  );
}

function StaffRow({ user }: { user: StaffUser }) {
  const me = useMe();
  const [editing, setEditing] = useState(false);
  const deactivate = useClientMutation(`/admin/users/${user.id}/deactivate`, 'POST');
  const reactivate = useClientMutation<{ isActive: boolean }>(`/admin/users/${user.id}`, 'PATCH');
  const self = me.data?.user.id === user.id;

  return (
    <li className="py-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className={`font-medium ${user.isActive ? '' : 'text-slate-400'}`}>{user.name} <span className="text-xs text-slate-500">@{user.login}</span></p>
          <p className="text-xs text-slate-500">{tDynamic('role', user.role)}{!user.isActive && ` · ${t('staff.inactive')}`}</p>
        </div>
        <div className="flex gap-2">
          <Button variant="ghost" onClick={() => setEditing((v) => !v)}>{t('common.edit')}</Button>
          {!self && (user.isActive
            ? <Button variant="secondary" loading={deactivate.isPending} onClick={() => deactivate.mutate()}>{t('staff.deactivate')}</Button>
            : <Button variant="secondary" loading={reactivate.isPending} onClick={() => reactivate.mutate({ isActive: true })}>{t('branch.enable')}</Button>)}
        </div>
      </div>
      {(deactivate.isError || reactivate.isError) && <ErrorBanner error={deactivate.error ?? reactivate.error} />}
      {editing && <div className="mt-3 rounded-lg bg-slate-50 p-3"><StaffForm user={user} onDone={() => setEditing(false)} /></div>}
    </li>
  );
}

export function StaffPage() {
  const q = useStaff();
  return (
    <div className="space-y-4">
      <Card title={t('staff.new')}><StaffForm /></Card>
      <Card title={t('nav.staff')}>
        {q.isPending ? <Spinner /> : q.isError ? <ErrorBanner error={q.error} /> : q.data.length === 0 ? <Empty /> : (
          <ul className="divide-y divide-slate-100">{q.data.map((u) => <StaffRow key={u.id} user={u} />)}</ul>
        )}
      </Card>
    </div>
  );
}

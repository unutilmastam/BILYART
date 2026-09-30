import { useState, type FormEvent } from 'react';
import { Link } from 'react-router';
import { useMe } from '../../auth/useMe';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Empty, ErrorBanner, Spinner } from '../../components/Feedback';
import { TextField } from '../../components/Field';
import { useBranches, useClientMutation } from '../../features/client/api';
import { t } from '../../i18n';
import { ApiError } from '../../lib/api';
import type { Branch } from '../../types/api';

function CreateBranch() {
  const [name, setName] = useState('');
  const [address, setAddress] = useState('');
  const m = useClientMutation<{ name: string; address?: string }>('/admin/branches', 'POST');
  const submit = (e: FormEvent) => {
    e.preventDefault();
    m.mutate({ name, address: address || undefined }, { onSuccess: () => { setName(''); setAddress(''); } });
  };
  return (
    <form onSubmit={submit} className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end" noValidate>
      <TextField label={t('branch.name')} value={name} onChange={(e) => setName(e.target.value)} error={m.error instanceof ApiError ? m.error.fieldError('name') : undefined} />
      <TextField label={t('branch.address')} value={address} onChange={(e) => setAddress(e.target.value)} />
      <Button type="submit" loading={m.isPending}>{t('branch.new')}</Button>
      {m.isError && !(m.error instanceof ApiError && m.error.fieldError('name')) && <div className="sm:col-span-3"><ErrorBanner error={m.error} /></div>}
    </form>
  );
}

export function BranchesPage() {
  const me = useMe();
  const q = useBranches();
  const canManage = me.data?.permissions.includes('branches.manage');

  return (
    <div className="space-y-4">
      {canManage && <Card title={t('branch.new')}><CreateBranch /></Card>}
      <Card title={t('nav.branches')}>
        {q.isPending ? <Spinner /> : q.isError ? <ErrorBanner error={q.error} onRetry={() => q.refetch()} /> : q.data.length === 0 ? <Empty /> : (
          <ul className="divide-y divide-slate-100">
            {q.data.map((b: Branch) => (
              <li key={b.id}>
                <Link to={`/client/branches/${b.id}`} className="flex items-center justify-between gap-3 py-3 hover:bg-slate-50">
                  <span className="min-w-0">
                    <span className="block truncate font-medium">{b.name}</span>
                    <span className="block text-xs text-slate-500">{b.address ?? '—'}</span>
                  </span>
                  {!b.isActive && <span className="rounded-full bg-slate-200 px-2 py-0.5 text-xs">{t('branch.disabled')}</span>}
                </Link>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}

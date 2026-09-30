import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Empty, ErrorBanner, Spinner, SuccessBanner } from '../../components/Feedback';
import { TextField } from '../../components/Field';
import { useBranches } from '../../features/client/api';
import { t } from '../../i18n';
import { api, ApiError } from '../../lib/api';
import { formatDateTime } from '../../lib/format';

interface TelegramChatInfo { id: string; title: string | null; branchId: string | null; receivesDailyReport: boolean; receivesAlerts: boolean }
interface TelegramState { configured: boolean; isActive: boolean; botUsername: string | null; lastError: string | null; lastErrorAt?: string | null; chats: TelegramChatInfo[] }

const KEY = ['client', 'telegram'];

export function TelegramPage() {
  const qc = useQueryClient();
  const q = useQuery({ queryKey: KEY, queryFn: () => api<TelegramState>('/admin/telegram') });
  const refresh = () => qc.invalidateQueries({ queryKey: KEY });
  const [token, setToken] = useState('');
  const save = useMutation({ mutationFn: () => api('/admin/telegram', { method: 'PUT', body: { botToken: token.trim() } }), onSuccess: () => { setToken(''); refresh(); } });
  const code = useMutation({ mutationFn: () => api<{ code: string; expiresAt: string; deepLink: string }>('/admin/telegram/link-code', { method: 'POST' }) });
  const test = useMutation({ mutationFn: () => api('/admin/telegram/test', { method: 'POST' }) });
  const disable = useMutation({ mutationFn: () => api('/admin/telegram', { method: 'DELETE' }), onSuccess: refresh });

  if (q.isPending) return <Spinner />;
  if (q.isError) return <ErrorBanner error={q.error} onRetry={() => q.refetch()} />;
  const s = q.data;
  const active = s.configured && s.isActive;

  return (
    <div className="space-y-4">
      <Card title={t('tg.title')} actions={active && <span className="text-sm font-semibold text-emerald-700">@{s.botUsername}</span>}>
        <p className="mb-3 text-sm text-slate-600">{t('tg.howto')}</p>
        <form onSubmit={(e: FormEvent) => { e.preventDefault(); save.mutate(); }} className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end" noValidate>
          <TextField label={t('tg.token')} hint={t('tg.tokenHint')} autoComplete="off" value={token} onChange={(e) => setToken(e.target.value)}
            error={save.error instanceof ApiError ? save.error.fieldError('botToken') : undefined} />
          <Button type="submit" disabled={token.trim().length < 20} loading={save.isPending}>{t('tg.save')}</Button>
        </form>
        {save.isError && !(save.error instanceof ApiError && save.error.fieldError('botToken')) && <ErrorBanner error={save.error} />}
        {s.lastError && <p className="mt-2 text-xs text-red-700">{t('tg.lastError')}: {s.lastError} ({formatDateTime(s.lastErrorAt)})</p>}
      </Card>

      {active && (
        <Card title={t('tg.linkCode')}>
          <p className="mb-3 text-sm text-slate-600">{t('tg.linkHint')}</p>
          <div className="flex flex-wrap items-center gap-3">
            <Button onClick={() => code.mutate()} loading={code.isPending}>{t('tg.linkCode')}</Button>
            {code.data && (
              <>
                <code className="rounded-lg bg-slate-100 px-3 py-2 text-lg font-bold tracking-widest">/start {code.data.code}</code>
                <a href={code.data.deepLink} target="_blank" rel="noreferrer" className="text-sm font-semibold text-brand-700">{t('tg.openBot')} ↗</a>
              </>
            )}
          </div>
        </Card>
      )}

      {active && (
        <Card title={t('tg.chats')} actions={<Button variant="ghost" onClick={refresh}>↻</Button>}>
          {s.chats.length === 0 ? <Empty /> : <ul className="divide-y divide-slate-100">{s.chats.map((c) => <ChatRow key={c.id} chat={c} onChange={refresh} />)}</ul>}
          <div className="mt-4 flex flex-wrap gap-2">
            <Button variant="secondary" onClick={() => test.mutate()} loading={test.isPending}>{t('tg.test')}</Button>
            <Button variant="danger" onClick={() => disable.mutate()} loading={disable.isPending}>{t('tg.disable')}</Button>
          </div>
          {test.isSuccess && <div className="mt-3"><SuccessBanner>{t('actions.done')}</SuccessBanner></div>}
          {(test.isError || disable.isError) && <div className="mt-3"><ErrorBanner error={test.error ?? disable.error} /></div>}
        </Card>
      )}
    </div>
  );
}

function ChatRow({ chat, onChange }: { chat: TelegramChatInfo; onChange: () => void }) {
  const branches = useBranches();
  const update = useMutation({ mutationFn: (body: Partial<TelegramChatInfo>) => api(`/admin/telegram/chats/${chat.id}`, { method: 'PATCH', body }), onSuccess: onChange });
  const remove = useMutation({ mutationFn: () => api(`/admin/telegram/chats/${chat.id}`, { method: 'DELETE' }), onSuccess: onChange });
  return (
    <li className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
      <span className="font-medium">{chat.title ?? chat.id}</span>
      <div className="flex flex-wrap items-center gap-3 text-sm">
        <select aria-label={t('table.branch')} className="min-h-11 rounded-lg border border-slate-300 px-2" value={chat.branchId ?? ''} onChange={(e) => update.mutate({ branchId: e.target.value || null })}>
          <option value="">{t('tg.allBranches')}</option>
          {branches.data?.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
        </select>
        <label className="flex items-center gap-1"><input type="checkbox" className="size-5" checked={chat.receivesDailyReport} onChange={(e) => update.mutate({ receivesDailyReport: e.target.checked })} />{t('tg.dailyReport')}</label>
        <label className="flex items-center gap-1"><input type="checkbox" className="size-5" checked={chat.receivesAlerts} onChange={(e) => update.mutate({ receivesAlerts: e.target.checked })} />{t('tg.alerts')}</label>
        <Button variant="ghost" loading={remove.isPending} onClick={() => remove.mutate()}>×</Button>
      </div>
    </li>
  );
}

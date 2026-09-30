import { useState } from 'react';
import { Card } from '../../components/Card';
import { Empty, ErrorBanner, Spinner } from '../../components/Feedback';
import { TextField } from '../../components/Field';
import { Pagination } from '../../components/Pagination';
import { useAudit } from '../../features/super/api';
import { t } from '../../i18n';
import { formatDateTime } from '../../lib/format';
import type { AuditEntry } from '../../types/api';

export function AuditList({ entries }: { entries: AuditEntry[] }) {
  if (entries.length === 0) return <Empty />;
  return (
    <ul className="divide-y divide-slate-100">
      {entries.map((e) => (
        <li key={e.id} className="flex flex-col gap-0.5 py-2 text-sm sm:flex-row sm:items-center sm:justify-between">
          <span>
            <span className="font-mono text-xs text-brand-700">{e.action}</span>
            {e.tenant && <span className="text-slate-600"> · {e.tenant}</span>}
            {e.actorName && <span className="text-slate-500"> · {e.actorName}</span>}
          </span>
          <time className="text-xs text-slate-500" dateTime={e.createdAt}>
            {formatDateTime(e.createdAt)}
          </time>
        </li>
      ))}
    </ul>
  );
}

export function AuditLogPage() {
  const [page, setPage] = useState(1);
  const [action, setAction] = useState('');
  const q = useAudit({ page, action: action.trim() || undefined });

  return (
    <Card title={t('nav.audit')}>
      <div className="mb-3 max-w-sm">
        <TextField label={t('audit.action')} placeholder="tenant." value={action} onChange={(e) => { setAction(e.target.value); setPage(1); }} />
      </div>
      {q.isPending ? <Spinner /> : q.isError ? <ErrorBanner error={q.error} onRetry={() => q.refetch()} /> : (
        <>
          <AuditList entries={q.data.data} />
          <Pagination {...q.data.meta} onPage={setPage} />
        </>
      )}
    </Card>
  );
}

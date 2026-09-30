import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { Empty, ErrorBanner, Spinner } from '../components/Feedback';
import { useNotifications } from '../features/notifications';
import { t } from '../i18n';
import { formatDateTime } from '../lib/format';

const tone = { INFO: 'border-slate-200', WARNING: 'border-amber-400', CRITICAL: 'border-red-500' } as const;

export function NotificationsPage({ area }: { area: 'client' | 'super' }) {
  const { list, readAll } = useNotifications(area);
  if (list.isPending) return <Spinner />;
  if (list.isError) return <ErrorBanner error={list.error} onRetry={() => list.refetch()} />;
  return (
    <Card title={t('nav.notifications')} actions={list.data.unread > 0 && <Button variant="ghost" onClick={() => readAll.mutate()} loading={readAll.isPending}>{t('notif.readAll')}</Button>}>
      {list.data.data.length === 0 ? <Empty /> : (
        <ul className="space-y-2">
          {list.data.data.map((n) => (
            <li key={n.id} className={`rounded-lg border-l-4 bg-white p-3 ring-1 ring-slate-100 ${tone[n.severity]} ${n.readAt ? 'opacity-60' : ''}`}>
              <p className="whitespace-pre-line text-sm">{n.text}</p>
              <time className="text-xs text-slate-500">{formatDateTime(n.createdAt)}</time>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

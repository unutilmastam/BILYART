import { useQuery } from '@tanstack/react-query';
import { Card } from '../../components/Card';
import { ErrorBanner, Spinner } from '../../components/Feedback';
import { DaysLeft, StatusBadge } from '../../components/StatusBadge';
import { t } from '../../i18n';
import { api } from '../../lib/api';
import { formatDate } from '../../lib/format';
import type { ClientSubscription } from '../../types/api';

/** Client area home. Always reachable, even when the subscription is inactive (spec §29). */
export function ClientHomePage() {
  const q = useQuery({ queryKey: ['client', 'subscription'], queryFn: () => api<ClientSubscription>('/admin/subscription') });
  if (q.isPending) return <Spinner />;
  if (q.isError) return <ErrorBanner error={q.error} onRetry={() => q.refetch()} />;
  const s = q.data;
  const inactive = s.status === 'EXPIRED' || s.status === 'SUSPENDED' || s.status === 'DEACTIVATED';

  return (
    <div className="space-y-4">
      <Card title={t('client.subscriptionTitle')} actions={<StatusBadge status={s.status} />}>
        <p className="text-sm text-slate-600">
          {t('clients.expires')}: <b>{formatDate(s.expiresAt)}</b> · <DaysLeft days={s.daysLeft} status={s.status} />
        </p>
        {inactive && (
          <div className="mt-3 space-y-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200">
            <p className="font-semibold">{t('client.inactive')}</p>
            {s.paymentInstructions && <p className="whitespace-pre-line">{s.paymentInstructions}</p>}
            {s.supportContact && <p>{t('client.contact')}: {s.supportContact}</p>}
          </div>
        )}
      </Card>
      <Card>
        <p className="text-sm text-slate-500">{t('client.comingSoon')}</p>
      </Card>
    </div>
  );
}

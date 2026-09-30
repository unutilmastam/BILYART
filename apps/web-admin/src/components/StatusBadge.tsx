import { t, tDynamic } from '../i18n';
import type { SubscriptionStatus } from '../types/api';

const tones: Record<SubscriptionStatus, string> = {
  ACTIVE: 'bg-emerald-100 text-emerald-800',
  EXPIRING_SOON: 'bg-amber-100 text-amber-800',
  EXPIRED: 'bg-red-100 text-red-800',
  SUSPENDED: 'bg-slate-200 text-slate-800',
  DEACTIVATED: 'bg-slate-800 text-white',
};

export function StatusBadge({ status }: { status: SubscriptionStatus }) {
  return <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ${tones[status]}`}>{tDynamic('status', status)}</span>;
}

export function DaysLeft({ days, status }: { days: number; status: SubscriptionStatus }) {
  if (status === 'EXPIRED' || status === 'DEACTIVATED') return <span className="text-slate-400">—</span>;
  return <span className={days <= 5 ? 'font-semibold text-amber-700' : ''}>{t('common.days', { n: days })}</span>;
}

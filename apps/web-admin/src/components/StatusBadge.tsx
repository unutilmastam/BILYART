import { t, tDynamic } from '../i18n';
import type { SubscriptionStatus } from '../types/api';

const tones: Record<SubscriptionStatus, string> = {
  ACTIVE: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  EXPIRING_SOON: 'bg-amber-50 text-amber-800 ring-amber-600/25',
  EXPIRED: 'bg-red-50 text-red-700 ring-red-600/20',
  SUSPENDED: 'bg-slate-100 text-slate-700 ring-slate-500/20',
  DEACTIVATED: 'bg-slate-800 text-white ring-slate-900',
};

export function StatusBadge({ status }: { status: SubscriptionStatus }) {
  return <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset ${tones[status]}`}>{tDynamic('status', status)}</span>;
}

export function DaysLeft({ days, status }: { days: number; status: SubscriptionStatus }) {
  if (status === 'EXPIRED' || status === 'DEACTIVATED') return <span className="text-slate-400">—</span>;
  return <span className={days <= 5 ? 'font-semibold text-amber-700' : ''}>{t('common.days', { n: days })}</span>;
}

import { BigButton, Centered, Ring, Spinner } from '../components/ui';
import type { Session } from '../features/session';
import { formatClock, formatCountdown, formatDuration, formatUzs } from '../lib/format';
import { t } from '../i18n';

export function StartScreen({ session, now, timezone, error, onDone }: { session: Session | null; now: number; timezone: string; error: string | null; onDone: () => void }) {
  if (error || session?.status === 'FAILED' || session?.status === 'CANCELLED') {
    return (
      <Centered>
        <p role="alert" className="max-w-3xl text-4xl font-bold text-red-300">
          {error ?? (session?.failureReason === 'PAID_NOT_STARTED' ? t('pay.failedPaid') : t('start.failed'))}
        </p>
        <BigButton onClick={onDone}>{t('common.done')}</BigButton>
      </Centered>
    );
  }
  if (!session || session.status !== 'ACTIVE' || !session.endAt) {
    return (
      <Centered>
        <Spinner />
        <p className="text-4xl font-bold">{t('start.starting')}</p>
      </Centered>
    );
  }
  const end = Date.parse(session.endAt);
  const start = session.startAt ? Date.parse(session.startAt) : end - session.durationMinutes * 60_000;
  const fraction = end > start ? (end - now) / (end - start) : 0;
  return (
    <Centered>
      <p className="text-5xl font-extrabold tracking-tight text-brand-300">{t('start.started')}</p>
      <Ring fraction={fraction}>
        <span className="tabular text-7xl font-bold">{formatCountdown(end - now)}</span>
        <span className="mt-2 text-xl text-white/70">{t('start.endsAt', { time: formatClock(end, timezone) })}</span>
      </Ring>
      {session.payment && <p className="text-2xl font-semibold text-accent-300">{t('start.paid', { amount: formatUzs(session.amount), time: formatDuration(session.durationMinutes) })}</p>}
      <BigButton onClick={onDone} className="min-w-72">
        {t('common.done')}
      </BigButton>
    </Centered>
  );
}

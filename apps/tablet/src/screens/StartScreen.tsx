import { BigButton, Centered, Spinner } from '../components/ui';
import type { Session } from '../features/session';
import { formatClock, formatCountdown } from '../lib/format';
import { t } from '../i18n';

export function StartScreen({ session, now, timezone, error, onDone }: { session: Session | null; now: number; timezone: string; error: string | null; onDone: () => void }) {
  if (error || session?.status === 'FAILED' || session?.status === 'CANCELLED') {
    return (
      <Centered>
        <p role="alert" className="text-4xl font-bold text-red-400">
          {error ?? t('start.failed')}
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
  return (
    <Centered>
      <p className="text-5xl font-extrabold text-brand-400">{t('start.started')}</p>
      <p className="font-mono text-8xl font-bold">{formatCountdown(end - now)}</p>
      <p className="text-3xl">{t('start.endsAt', { time: formatClock(end, timezone) })}</p>
      <BigButton onClick={onDone}>{t('common.done')}</BigButton>
    </Centered>
  );
}

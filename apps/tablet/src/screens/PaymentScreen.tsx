import { BigButton, ScreenTitle, Spinner } from '../components/ui';
import type { Session } from '../features/session';
import { formatCountdown, formatDuration, formatUzs } from '../lib/format';
import { t } from '../i18n';

/**
 * Bill acceptor payment: the customer feeds bills, the server counts them (the tablet only reads).
 * No change is given — extra money is extra time; stopping early plays what was paid.
 */
export function PaymentScreen({ session, pricePerHour, now, busy, error, onStop }: { session: Session; pricePerHour: number; now: number; busy: boolean; error: string | null; onStop: () => void }) {
  const payment = session.payment!;
  const required = session.amount;
  const paid = payment.paid;
  const left = Math.max(0, required - paid);
  const share = required > 0 ? Math.min(1, paid / required) : 0;
  const minutes = pricePerHour > 0 ? Math.min(720, Math.floor((paid * 60) / pricePerHour)) : 0;
  const until = payment.acceptUntil ? Date.parse(payment.acceptUntil) : null;
  const closing = !payment.accepting;

  return (
    <div className="flex h-full flex-col gap-6 p-6 lg:p-8">
      <ScreenTitle>{closing ? t('pay.closingTitle') : t('pay.title')}</ScreenTitle>
      <div className="flex flex-1 flex-col items-center justify-center gap-7">
        <div className="glass w-full max-w-3xl rounded-[32px] p-10">
          <div className="grid grid-cols-2 gap-8 text-center">
            <div>
              <p className="text-2xl text-white/60">{t('pay.required')}</p>
              <p className="tabular mt-1 text-5xl font-extrabold">{formatUzs(required)}</p>
            </div>
            <div>
              <p className="text-2xl text-white/60">{t('pay.paid')}</p>
              <p className="tabular mt-1 text-5xl font-extrabold text-accent-300" aria-live="polite">{formatUzs(paid)}</p>
            </div>
          </div>
          <div className="mt-8 h-5 overflow-hidden rounded-full bg-white/10" role="progressbar" aria-valuemin={0} aria-valuemax={required} aria-valuenow={paid}>
            <div className="h-full rounded-full bg-gradient-to-r from-brand-400 to-accent-300 transition-all duration-500" style={{ width: `${share * 100}%` }} />
          </div>
          <div className="mt-5 flex flex-wrap items-baseline justify-between gap-3 text-2xl">
            <span className="text-white/70">{left > 0 ? t('pay.left', { amount: formatUzs(left) }) : t('pay.done')}</span>
            {paid > 0 && <span className="font-semibold text-brand-200">{t('pay.buys', { time: formatDuration(Math.max(minutes, 0)) })}</span>}
          </div>
        </div>

        {closing ? (
          <p className="flex items-center gap-4 text-3xl font-semibold">
            <Spinner /> {paid > 0 ? t('pay.startingPaid', { amount: formatUzs(paid) }) : t('pay.cancelling')}
          </p>
        ) : (
          <>
            <p className="max-w-3xl text-center text-2xl text-white/85">{t('pay.hint')}</p>
            {until && <p className="tabular text-xl text-white/60">{t('pay.waiting', { time: formatCountdown(until - now) })}</p>}
          </>
        )}
        {error && (
          <p role="alert" className="rounded-2xl bg-red-600/90 px-6 py-3 text-2xl font-semibold">
            {error}
          </p>
        )}
      </div>
      {!closing && (
        <div className="flex justify-center">
          <BigButton variant="ghost" onClick={onStop} disabled={busy} className="min-w-96">
            {paid > 0 ? t('pay.playPaid') : t('common.cancel')}
          </BigButton>
        </div>
      )}
    </div>
  );
}

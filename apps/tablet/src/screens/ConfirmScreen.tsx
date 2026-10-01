import type { TabletDurationQuote, TabletTable } from '@bilyart/protocol';
import { BigButton, ScreenTitle, Spinner } from '../components/ui';
import { formatDuration, formatUzs } from '../lib/format';
import { t } from '../i18n';

export function ConfirmScreen(props: { table: TabletTable; quote: TabletDurationQuote; busy: boolean; error: string | null; privacyNotice: string; onConfirm: () => void; onBack: () => void }) {
  const { table, quote, busy, error } = props;
  return (
    <div className="flex h-full flex-col gap-6 p-6 lg:p-8">
      <ScreenTitle step="2 / 3">{t('confirm.title')}</ScreenTitle>
      <div className="flex flex-1 flex-col items-center justify-center gap-6">
        <dl className="glass grid w-full max-w-2xl grid-cols-[auto_1fr] items-baseline gap-x-10 gap-y-5 rounded-[32px] p-10 text-3xl">
          <dt className="text-white/60">{t('confirm.table')}</dt>
          <dd className="text-right font-bold">{table.name}</dd>
          <dt className="text-white/60">{t('confirm.duration')}</dt>
          <dd className="text-right font-bold">{formatDuration(quote.minutes)}</dd>
          <dt className="pt-4 text-white/60">{t('confirm.amount')}</dt>
          <dd className="tabular pt-4 text-right text-5xl font-extrabold text-accent-300">{formatUzs(quote.amount)}</dd>
        </dl>
        <p className="text-xl text-white/80">{t('confirm.payNote')}</p>
        {props.privacyNotice && <p className="max-w-3xl text-center text-base text-white/55">{props.privacyNotice}</p>}
        {error && (
          <p role="alert" className="rounded-2xl bg-red-600/90 px-6 py-3 text-2xl font-semibold">
            {error}
          </p>
        )}
      </div>
      <div className="flex justify-between gap-4">
        <BigButton variant="ghost" onClick={props.onBack} disabled={busy}>
          {t('common.back')}
        </BigButton>
        <BigButton onClick={props.onConfirm} disabled={busy} className="min-w-72">
          {busy ? <Spinner /> : t('confirm.next')}
        </BigButton>
      </div>
    </div>
  );
}

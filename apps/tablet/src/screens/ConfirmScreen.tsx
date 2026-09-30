import type { TabletDurationQuote, TabletTable } from '@bilyart/protocol';
import { BigButton, Spinner } from '../components/ui';
import { formatDuration, formatUzs } from '../lib/format';
import { t } from '../i18n';

export function ConfirmScreen(props: { table: TabletTable; quote: TabletDurationQuote; busy: boolean; error: string | null; privacyNotice: string; onConfirm: () => void; onBack: () => void }) {
  const { table, quote, busy, error } = props;
  return (
    <div className="flex h-full flex-col items-center justify-center gap-8 p-8">
      <h1 className="text-4xl font-bold">{t('confirm.title')}</h1>
      <dl className="grid w-full max-w-2xl grid-cols-2 gap-x-8 gap-y-4 rounded-3xl bg-slate-900 p-8 text-3xl">
        <dt className="text-slate-400">{t('confirm.table')}</dt>
        <dd className="font-bold">{table.name}</dd>
        <dt className="text-slate-400">{t('confirm.duration')}</dt>
        <dd className="font-bold">{formatDuration(quote.minutes)}</dd>
        <dt className="text-slate-400">{t('confirm.amount')}</dt>
        <dd className="text-4xl font-extrabold text-brand-400">{formatUzs(quote.amount)}</dd>
      </dl>
      <p className="text-xl text-slate-300">{t('confirm.payNote')}</p>
      {props.privacyNotice && <p className="max-w-3xl text-center text-base text-slate-400">{props.privacyNotice}</p>}
      {error && (
        <p role="alert" className="rounded-xl bg-red-600 px-6 py-3 text-2xl font-semibold">
          {error}
        </p>
      )}
      <div className="flex gap-4">
        <BigButton variant="ghost" onClick={props.onBack} disabled={busy}>
          {t('common.back')}
        </BigButton>
        <BigButton onClick={props.onConfirm} disabled={busy}>
          {busy ? <Spinner /> : t('confirm.next')}
        </BigButton>
      </div>
    </div>
  );
}

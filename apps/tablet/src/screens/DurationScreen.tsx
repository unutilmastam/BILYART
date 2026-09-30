import type { TabletDurationQuote, TabletTable } from '@bilyart/protocol';
import { BigButton } from '../components/ui';
import { formatDuration, formatUzs } from '../lib/format';
import { t } from '../i18n';

export function DurationScreen({ table, onPick, onBack }: { table: TabletTable; onPick: (q: TabletDurationQuote) => void; onBack: () => void }) {
  const quotes = table.pricing?.durations ?? [];
  return (
    <div className="flex h-full flex-col gap-6 p-6">
      <h1 className="text-4xl font-bold">{t('duration.title', { table: table.name })}</h1>
      <ul className="grid flex-1 auto-rows-fr grid-cols-2 gap-5 md:grid-cols-3">
        {quotes.map((q) => (
          <li key={q.minutes}>
            <button type="button" onClick={() => onPick(q)} className="flex h-full min-h-32 w-full flex-col items-center justify-center gap-2 rounded-3xl bg-slate-800 ring-4 ring-slate-600 active:bg-slate-700">
              <span className="text-4xl font-bold">{formatDuration(q.minutes)}</span>
              <span className="text-2xl text-brand-400">{formatUzs(q.amount)}</span>
            </button>
          </li>
        ))}
      </ul>
      <BigButton variant="ghost" onClick={onBack} className="self-start">
        {t('common.back')}
      </BigButton>
    </div>
  );
}

import type { TabletDurationQuote, TabletTable } from '@bilyart/protocol';
import { BigButton, ScreenTitle } from '../components/ui';
import { formatDuration, formatUzs } from '../lib/format';
import { t } from '../i18n';

export function DurationScreen({ table, onPick, onBack }: { table: TabletTable; onPick: (q: TabletDurationQuote) => void; onBack: () => void }) {
  const quotes = table.pricing?.durations ?? [];
  return (
    <div className="flex h-full flex-col gap-6 p-6 lg:p-8">
      <ScreenTitle step="1 / 3">{t('duration.title', { table: table.name })}</ScreenTitle>
      <ul className="grid flex-1 auto-rows-[minmax(10rem,1fr)] content-center grid-cols-2 gap-5 md:grid-cols-3">
        {quotes.map((q) => (
          <li key={q.minutes} className="max-h-60">
            <button
              type="button"
              onClick={() => onPick(q)}
              className="glass group flex size-full flex-col items-center justify-center gap-3 rounded-[28px] transition active:scale-[0.98] active:bg-white/15"
            >
              <span className="px-4 text-center text-4xl font-extrabold tracking-tight xl:text-5xl">{formatDuration(q.minutes)}</span>
              <span className="tabular rounded-full bg-accent-400/15 px-5 py-1.5 text-2xl font-bold text-accent-300 ring-1 ring-accent-400/30">{formatUzs(q.amount)}</span>
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

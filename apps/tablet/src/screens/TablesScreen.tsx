import type { TableStatus, TabletTable } from '@bilyart/protocol';
import { ScreenTitle } from '../components/ui';
import { formatCountdown, formatUzs } from '../lib/format';
import { t, type MessageKey } from '../i18n';

/** Card look per status: lit felt when free, dark glass with a coloured edge otherwise. */
const LOOK: Record<TableStatus, { label: MessageKey; card: string; badge: string }> = {
  AVAILABLE: { label: 'tables.free', card: 'felt-card active:brightness-110', badge: 'bg-white/90 text-brand-800' },
  RESERVED: { label: 'tables.reserved', card: 'glass ring-2 ring-sky-400/50', badge: 'bg-sky-400/20 text-sky-200' },
  STARTING: { label: 'tables.starting', card: 'glass ring-2 ring-sky-400/50', badge: 'bg-sky-400/20 text-sky-200' },
  BUSY: { label: 'tables.busy', card: 'glass ring-2 ring-rose-500/60', badge: 'bg-rose-500/20 text-rose-200' },
  WARNING: { label: 'tables.warning', card: 'glass glow-warning', badge: 'bg-accent-400 text-ink' },
  DISABLED: { label: 'tables.disabled', card: 'glass opacity-55', badge: 'bg-white/10 text-slate-300' },
  DEVICE_OFFLINE: { label: 'tables.offline', card: 'glass opacity-55', badge: 'bg-white/10 text-slate-300' },
  CLOSED: { label: 'tables.closed', card: 'glass opacity-55', badge: 'bg-white/10 text-slate-300' },
};

/** Only AVAILABLE tables with a price can be chosen, and only while online (spec §38). */
export function canChoose(table: TabletTable, online: boolean): boolean {
  return online && table.status === 'AVAILABLE' && table.pricing !== null && table.pricing.durations.length > 0;
}

/** Remaining share of a running session, for the progress bar. */
function remainingShare(table: TabletTable, now: number): number | null {
  const s = table.session;
  if (!s?.startAt || !s.endAt) return null;
  const start = Date.parse(s.startAt);
  const end = Date.parse(s.endAt);
  return end > start ? Math.min(1, Math.max(0, (end - now) / (end - start))) : null;
}

export function TablesScreen({ tables, now, online, onChoose }: { tables: TabletTable[]; now: number; online: boolean; onChoose: (t: TabletTable) => void }) {
  return (
    <div className="flex h-full flex-col gap-6 p-6 lg:p-8">
      <ScreenTitle>{t('tables.title')}</ScreenTitle>
      <ul className="-m-2 grid flex-1 auto-rows-[minmax(13rem,1fr)] content-start grid-cols-2 gap-5 overflow-y-auto p-2 md:grid-cols-3 xl:grid-cols-4">
        {tables.map((table) => {
          const look = LOOK[table.status];
          const endAt = table.session?.endAt ? Date.parse(table.session.endAt) : null;
          const running = endAt !== null && (table.status === 'BUSY' || table.status === 'WARNING');
          const share = running ? remainingShare(table, now) : null;
          const enabled = canChoose(table, online);
          return (
            <li key={table.id} className="max-h-72">
              <button
                type="button"
                disabled={!enabled}
                onClick={() => onChoose(table)}
                className={`relative flex size-full flex-col justify-between overflow-hidden rounded-[28px] p-5 text-left transition active:scale-[0.98] ${look.card}`}
              >
                <div className="flex items-start justify-between gap-3">
                  <span className="grid size-16 shrink-0 place-items-center rounded-full bg-gradient-to-br from-white to-slate-200 text-3xl font-extrabold text-ink shadow-lg tabular">
                    {table.number}
                  </span>
                  <span className={`rounded-full px-3 py-1 text-center text-lg font-bold leading-tight ${look.badge}`}>{t(look.label)}</span>
                </div>
                <div>
                  <span className="block text-2xl font-bold tracking-tight">{table.name}</span>
                  {running && endAt !== null && <span className="tabular mt-1 block text-4xl font-bold">{formatCountdown(endAt - now)}</span>}
                  {table.status === 'AVAILABLE' && (
                    <span className="mt-1 block text-xl font-semibold text-white/90">
                      {table.pricing ? t('tables.perHour', { price: formatUzs(table.pricing.pricePerHour) }) : t('tables.noPrice')}
                    </span>
                  )}
                </div>
                {share !== null && (
                  <span className="absolute inset-x-0 bottom-0 h-1.5 bg-white/10" aria-hidden>
                    <span className={`block h-full ${table.status === 'WARNING' ? 'bg-accent-400' : 'bg-rose-400'}`} style={{ width: `${share * 100}%` }} />
                  </span>
                )}
              </button>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

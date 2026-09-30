import type { TableStatus, TabletTable } from '@bilyart/protocol';
import { formatCountdown, formatUzs } from '../lib/format';
import { t, type MessageKey } from '../i18n';

const LOOK: Record<TableStatus, { label: MessageKey; card: string }> = {
  AVAILABLE: { label: 'tables.free', card: 'bg-brand-600 ring-brand-400 active:bg-brand-500' },
  RESERVED: { label: 'tables.reserved', card: 'bg-slate-800 ring-slate-600' },
  STARTING: { label: 'tables.starting', card: 'bg-slate-800 ring-slate-600' },
  BUSY: { label: 'tables.busy', card: 'bg-red-900 ring-red-700' },
  WARNING: { label: 'tables.warning', card: 'bg-amber-700 ring-amber-500' },
  DISABLED: { label: 'tables.disabled', card: 'bg-slate-800 ring-slate-700 opacity-60' },
  DEVICE_OFFLINE: { label: 'tables.offline', card: 'bg-slate-800 ring-slate-700 opacity-60' },
  CLOSED: { label: 'tables.closed', card: 'bg-slate-800 ring-slate-700 opacity-60' },
};

/** Only AVAILABLE tables with a price can be chosen, and only while online (spec §38). */
export function canChoose(table: TabletTable, online: boolean): boolean {
  return online && table.status === 'AVAILABLE' && table.pricing !== null && table.pricing.durations.length > 0;
}

export function TablesScreen({ tables, now, online, onChoose }: { tables: TabletTable[]; now: number; online: boolean; onChoose: (t: TabletTable) => void }) {
  return (
    <div className="flex h-full flex-col p-6">
      <h1 className="mb-6 text-4xl font-bold">{t('tables.title')}</h1>
      <ul className="grid flex-1 auto-rows-fr grid-cols-2 gap-5 overflow-y-auto md:grid-cols-3 xl:grid-cols-4">
        {tables.map((table) => {
          const look = LOOK[table.status];
          const endAt = table.session?.endAt ? Date.parse(table.session.endAt) : null;
          const enabled = canChoose(table, online);
          return (
            <li key={table.id}>
              <button
                type="button"
                disabled={!enabled}
                onClick={() => onChoose(table)}
                className={`flex h-full min-h-40 w-full flex-col items-center justify-center gap-2 rounded-3xl p-4 ring-4 transition ${look.card}`}
              >
                <span className="text-5xl font-extrabold">{table.number}</span>
                <span className="text-xl font-semibold">{table.name}</span>
                <span className="text-2xl font-bold">{t(look.label)}</span>
                {endAt && (table.status === 'BUSY' || table.status === 'WARNING') && <span className="font-mono text-3xl">{formatCountdown(endAt - now)}</span>}
                {table.status === 'AVAILABLE' && (
                  <span className="text-lg">{table.pricing ? t('tables.perHour', { price: formatUzs(table.pricing.pricePerHour) }) : t('tables.noPrice')}</span>
                )}
              </button>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

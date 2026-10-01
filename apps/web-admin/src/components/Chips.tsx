import { tDynamic } from '../i18n';
import type { PaymentStatusValue, SessionStatusValue, TableStatusValue } from '../types/api';

/** Soft pill with a status dot — one look for every status in the panel. */
function Pill({ tone, dot, children }: { tone: string; dot: string; children: React.ReactNode }) {
  return (
    <span className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset ${tone}`}>
      <span className={`size-1.5 rounded-full ${dot}`} aria-hidden />
      {children}
    </span>
  );
}

const tableTone: Record<TableStatusValue, [string, string]> = {
  AVAILABLE: ['bg-emerald-50 text-emerald-700 ring-emerald-600/20', 'bg-emerald-500'],
  RESERVED: ['bg-sky-50 text-sky-700 ring-sky-600/20', 'bg-sky-500'],
  STARTING: ['bg-sky-50 text-sky-700 ring-sky-600/20', 'bg-sky-500 animate-pulse'],
  BUSY: ['bg-rose-50 text-rose-700 ring-rose-600/20', 'bg-rose-500'],
  WARNING: ['bg-amber-50 text-amber-800 ring-amber-600/25', 'bg-amber-500 animate-pulse'],
  DISABLED: ['bg-slate-50 text-slate-500 ring-slate-500/15', 'bg-slate-400'],
  DEVICE_OFFLINE: ['bg-slate-100 text-slate-700 ring-slate-500/20', 'bg-slate-500'],
  CLOSED: ['bg-slate-50 text-slate-600 ring-slate-500/15', 'bg-slate-400'],
};

export function TableStatusChip({ status }: { status: TableStatusValue }) {
  const [tone, dot] = tableTone[status];
  return <Pill tone={tone} dot={dot}>{tDynamic('tstatus', status)}</Pill>;
}

const sessionTone: Record<SessionStatusValue, [string, string]> = {
  RESERVED: ['bg-sky-50 text-sky-700 ring-sky-600/20', 'bg-sky-500'],
  STARTING: ['bg-sky-50 text-sky-700 ring-sky-600/20', 'bg-sky-500 animate-pulse'],
  ACTIVE: ['bg-rose-50 text-rose-700 ring-rose-600/20', 'bg-rose-500'],
  COMPLETING: ['bg-amber-50 text-amber-800 ring-amber-600/25', 'bg-amber-500'],
  COMPLETED: ['bg-slate-50 text-slate-700 ring-slate-500/15', 'bg-slate-400'],
  CANCELLED: ['bg-slate-50 text-slate-500 ring-slate-500/15', 'bg-slate-300'],
  FAILED: ['bg-red-600 text-white ring-red-700', 'bg-white'],
};

export function SessionStatusChip({ status }: { status: SessionStatusValue }) {
  const [tone, dot] = sessionTone[status];
  return <Pill tone={tone} dot={dot}>{tDynamic('sstatus', status)}</Pill>;
}

export function PaymentChip({ status }: { status: PaymentStatusValue }) {
  const [tone, dot] =
    status === 'PAID'
      ? ['bg-emerald-50 text-emerald-700 ring-emerald-600/20', 'bg-emerald-500']
      : status === 'WAIVED'
        ? ['bg-slate-50 text-slate-600 ring-slate-500/15', 'bg-slate-400']
        : ['bg-amber-50 text-amber-800 ring-amber-600/25', 'bg-amber-500'];
  return <Pill tone={tone} dot={dot}>{tDynamic('pay', status)}</Pill>;
}

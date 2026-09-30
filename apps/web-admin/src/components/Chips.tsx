import { tDynamic } from '../i18n';
import type { PaymentStatusValue, SessionStatusValue, TableStatusValue } from '../types/api';

const tableTone: Record<TableStatusValue, string> = {
  AVAILABLE: 'bg-emerald-100 text-emerald-800 ring-emerald-300',
  RESERVED: 'bg-sky-100 text-sky-800 ring-sky-300',
  STARTING: 'bg-sky-100 text-sky-800 ring-sky-300',
  BUSY: 'bg-red-100 text-red-800 ring-red-300',
  WARNING: 'bg-amber-100 text-amber-900 ring-amber-400',
  DISABLED: 'bg-slate-100 text-slate-500 ring-slate-200',
  DEVICE_OFFLINE: 'bg-slate-200 text-slate-700 ring-slate-300',
  CLOSED: 'bg-slate-100 text-slate-600 ring-slate-200',
};

export function TableStatusChip({ status }: { status: TableStatusValue }) {
  return <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ${tableTone[status]}`}>{tDynamic('tstatus', status)}</span>;
}

const sessionTone: Record<SessionStatusValue, string> = {
  RESERVED: 'bg-sky-100 text-sky-800',
  STARTING: 'bg-sky-100 text-sky-800',
  ACTIVE: 'bg-red-100 text-red-800',
  COMPLETING: 'bg-amber-100 text-amber-800',
  COMPLETED: 'bg-slate-100 text-slate-700',
  CANCELLED: 'bg-slate-100 text-slate-500',
  FAILED: 'bg-red-600 text-white',
};

export function SessionStatusChip({ status }: { status: SessionStatusValue }) {
  return <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${sessionTone[status]}`}>{tDynamic('sstatus', status)}</span>;
}

export function PaymentChip({ status }: { status: PaymentStatusValue }) {
  const tone = status === 'PAID' ? 'bg-emerald-100 text-emerald-800' : status === 'WAIVED' ? 'bg-slate-100 text-slate-700' : 'bg-amber-100 text-amber-900';
  return <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${tone}`}>{tDynamic('pay', status)}</span>;
}

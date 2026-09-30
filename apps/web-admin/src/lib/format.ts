/** Display helpers. The server sends UTC ISO strings and integer UZS; we only format. */

export const DISPLAY_TIMEZONE = 'Asia/Tashkent';

/** 500000 → "500 000 so'm". Money is always an integer (UZS). */
export function formatMoney(amount: number): string {
  if (!Number.isInteger(amount)) throw new Error('Money must be an integer (UZS)');
  const grouped = Math.abs(amount).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  return `${amount < 0 ? '-' : ''}${grouped} so'm`;
}

function parts(iso: string, timeZone: string): Record<string, string> {
  const fmt = new Intl.DateTimeFormat('en-GB', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  });
  return Object.fromEntries(fmt.formatToParts(new Date(iso)).map((p) => [p.type, p.value]));
}

/** "2026-11-30T00:00:00Z" → "30.11.2026" (in the display timezone). */
export function formatDate(iso: string | null | undefined, timeZone = DISPLAY_TIMEZONE): string {
  if (!iso) return '—';
  const p = parts(iso, timeZone);
  return `${p.day}.${p.month}.${p.year}`;
}

/** → "30.11.2026 05:00" */
export function formatDateTime(iso: string | null | undefined, timeZone = DISPLAY_TIMEZONE): string {
  if (!iso) return '—';
  const p = parts(iso, timeZone);
  return `${p.day}.${p.month}.${p.year} ${p.hour}:${p.minute}`;
}

/** Parses a user-typed amount like "500 000" → 500000; returns null if not a whole non-negative number. */
export function parseMoneyInput(value: string): number | null {
  const digits = value.replace(/[\s ,.]/g, '');
  if (!/^\d{1,13}$/.test(digits)) return null;
  return Number(digits);
}

/** 195 → "3 soat 15 daq" */
export function formatMinutes(minutes: number): string {
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  if (h === 0) return `${m} daq`;
  return m === 0 ? `${h} soat` : `${h} soat ${m} daq`;
}

/** Local "HH:MM" of an ISO instant in the display timezone. */
export function formatTime(iso: string | null | undefined, timeZone = DISPLAY_TIMEZONE): string {
  if (!iso) return '—';
  return formatDateTime(iso, timeZone).slice(-5);
}

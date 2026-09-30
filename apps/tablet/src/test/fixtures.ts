import type { TabletBootstrapResponse, TabletTable } from '@bilyart/protocol';

export const ID = (n: number) => `01J${String(n).padStart(23, '0')}`;

export function table(n: number, extra: Partial<TabletTable> = {}): TabletTable {
  return {
    id: ID(n),
    number: n,
    name: `${n}-stol`,
    status: 'AVAILABLE',
    session: null,
    pricing: { type: 'HOURLY', pricePerHour: 20000, durations: [{ minutes: 30, amount: 10000 }, { minutes: 60, amount: 20000 }] },
    ...extra,
  };
}

export function bootstrap(tables: TabletTable[], extra: Partial<TabletBootstrapResponse['settings']> = {}): TabletBootstrapResponse {
  return {
    serverTime: new Date().toISOString(),
    tablet: { id: ID(900), code: 'TABLET-ABC123' },
    branch: { id: ID(800), name: 'Markaz', tenantName: 'Ali Billiard', timezone: 'Asia/Tashkent', isOpenNow: true },
    settings: {
      locale: 'uz',
      photoRequired: true,
      privacyNotice: '',
      warnBeforeSec: 300,
      warningAudio: { mode: 'TTS', text: '{table}-stol, sizda 5 daqiqa vaqtingiz qoldi.' },
      ...extra,
    },
    tables,
  };
}

export function session(status: string, extra: Record<string, unknown> = {}) {
  return {
    serverTime: new Date().toISOString(),
    session: {
      id: ID(500),
      tableId: ID(1),
      tableNumber: 1,
      status,
      durationMinutes: 60,
      amount: 20000,
      reservedUntil: status === 'RESERVED' ? new Date(Date.now() + 120_000).toISOString() : null,
      startAt: null,
      endAt: null,
      hasPhoto: false,
      failureReason: null,
      ...extra,
    },
  };
}

import { describe, expect, it } from 'vitest';
import { table } from '../test/fixtures';
import { ServerClock } from './clock';
import { isWellPlaced } from './face';
import { formatCountdown, formatDuration, formatUzs } from './format';
import { KEYS, storage } from './storage';
import { warningText, WarningScheduler } from './warnings';

describe('server clock', () => {
  it('uses the offset of the sample with the smallest round trip', () => {
    let local = 1_000_000;
    const c = new ServerClock(() => local);
    c.sample(new Date(1_060_000).toISOString(), 1_000_000, 1_004_000); // rtt 4000 → offset 58000
    c.sample(new Date(1_050_500).toISOString(), 1_000_000, 1_001_000); // rtt 1000 → offset 50000
    expect(c.offsetMs).toBe(50_000);
    local = 2_000_000;
    expect(c.now()).toBe(2_050_000);
  });

  it('ignores garbage samples', () => {
    const c = new ServerClock(() => 0);
    c.sample('not a date', 0, 10);
    c.sample(new Date(5).toISOString(), 10, 0);
    expect(c.offsetMs).toBe(0);
  });
});

describe('formatting', () => {
  it('formats money as integer so\'m', () => {
    expect(formatUzs(20000)).toBe('20 000 so\'m');
    expect(formatUzs(1250000)).toBe('1 250 000 so\'m');
  });
  it('formats durations and countdowns', () => {
    expect(formatDuration(30)).toBe('30 daqiqa');
    expect(formatDuration(60)).toBe('1 soat');
    expect(formatDuration(90)).toBe('1 soat 30 daqiqa');
    expect(formatCountdown(299_001)).toBe('5:00');
    expect(formatCountdown(3_909_000)).toBe('1:05:09');
    expect(formatCountdown(-5000)).toBe('0:00');
  });
});

describe('five-minute warning', () => {
  const end = Date.parse('2026-10-01T18:00:00Z');
  const active = table(3, { status: 'WARNING', session: { id: 'S3', status: 'ACTIVE', startAt: '2026-10-01T17:00:00Z', endAt: '2026-10-01T18:00:00Z' } });

  it('fires once per session inside the window, from local time only', () => {
    const s = new WarningScheduler(300);
    expect(s.due([active], end - 301_000)).toEqual([]);
    expect(s.due([active], end - 299_000)).toEqual([active]);
    expect(s.due([active], end - 200_000)).toEqual([]); // already announced
  });

  it('never fires for ended, non-active or session-less tables', () => {
    const s = new WarningScheduler(300);
    expect(s.due([active], end + 1000)).toEqual([]);
    expect(s.due([{ ...active, session: { ...active.session!, status: 'STARTING' } }], end - 100_000)).toEqual([]);
    expect(s.due([table(1)], end - 100_000)).toEqual([]);
  });

  it('fills the configurable text', () => {
    expect(warningText('{table}-stol, sizda 5 daqiqa vaqtingiz qoldi.', active)).toBe('3-stol, sizda 5 daqiqa vaqtingiz qoldi.');
  });
});

describe('face placement (detection only)', () => {
  const face = { x: 0.35, y: 0.25, width: 0.3, height: 0.4, score: 0.9 };
  it('accepts exactly one big, centred face', () => {
    expect(isWellPlaced([face])).toBe(true);
    expect(isWellPlaced([])).toBe(false);
    expect(isWellPlaced([face, face])).toBe(false);
    expect(isWellPlaced([{ ...face, width: 0.1 }])).toBe(false);
    expect(isWellPlaced([{ ...face, x: 0.75 }])).toBe(false);
    expect(isWellPlaced([{ ...face, score: 0.3 }])).toBe(false);
  });
});

describe('storage', () => {
  it('round-trips values in IndexedDB', async () => {
    await storage.set(KEYS.token, 'abc');
    expect(await storage.get(KEYS.token)).toBe('abc');
    await storage.del(KEYS.token);
    expect(await storage.get(KEYS.token)).toBeUndefined();
  });
});

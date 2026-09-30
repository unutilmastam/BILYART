import { describe, expect, it } from 'vitest';
import { formatDate, formatDateTime, formatMoney, parseMoneyInput } from './format';

describe('format', () => {
  it('formats integer UZS with non-breaking spaces', () => {
    expect(formatMoney(500000)).toBe('500 000 so\'m');
    expect(formatMoney(0)).toBe('0 so\'m');
    expect(formatMoney(1234567)).toBe('1 234 567 so\'m');
  });

  it('refuses fractional money', () => {
    expect(() => formatMoney(10.5)).toThrow();
  });

  it('shows dates in Tashkent time', () => {
    expect(formatDate('2026-11-29T20:00:00Z')).toBe('30.11.2026');
    expect(formatDateTime('2026-11-30T00:00:00Z')).toBe('30.11.2026 05:00');
    expect(formatDate(null)).toBe('—');
  });

  it('parses typed amounts strictly', () => {
    expect(parseMoneyInput('500 000')).toBe(500000);
    expect(parseMoneyInput('500.000')).toBe(500000);
    expect(parseMoneyInput('12a')).toBeNull();
    expect(parseMoneyInput('')).toBeNull();
    expect(parseMoneyInput('-5')).toBeNull();
  });
});

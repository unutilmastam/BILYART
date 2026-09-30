import { uz, type MessageKey } from './uz';

const dictionaries = { uz } as const;
let current: keyof typeof dictionaries = 'uz';

export function setLocale(locale: keyof typeof dictionaries): void {
  current = locale;
}

/** t('common.page', { page: 2, total: 40 }) */
export function t(key: MessageKey, params: Record<string, string | number> = {}): string {
  const template: string = dictionaries[current][key] ?? key;
  return template.replace(/\{(\w+)\}/g, (_, name: string) => String(params[name] ?? `{${name}}`));
}

/** For keys built at runtime from server enums, e.g. status.ACTIVE. Falls back to the raw value. */
export function tDynamic(prefix: string, value: string): string {
  const key = `${prefix}.${value}` as MessageKey;
  return key in dictionaries[current] ? t(key) : value;
}

import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { createValidator } from '../src/validator';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const { schemaIds, get } = createValidator();
const schemaNames = schemaIds
  .map((id) => id.replace('https://protocol.bilyart/', '').replace('.schema.json', ''))
  .filter((n) => n !== 'common');

/** Example file name → schema name: longest schema name that prefixes the file name. */
function schemaFor(file: string): string {
  const base = file.replace(/\.json$/, '');
  if (base.startsWith('common.')) return base.split('.').slice(0, 2).join('.');
  const match = schemaNames
    .filter((n) => base === n || base.startsWith(`${n}.`))
    .sort((a, b) => b.length - a.length)[0];
  if (!match) throw new Error(`No schema for example ${file}`);
  return match;
}

const load = (dir: string) =>
  readdirSync(join(root, 'examples', dir))
    .filter((f) => f.endsWith('.json'))
    .map((f) => ({ file: f, data: JSON.parse(readFileSync(join(root, 'examples', dir, f), 'utf8')) }));

describe('protocol schemas', () => {
  it('compile in strict mode', () => {
    expect(schemaNames.length).toBeGreaterThan(10);
    for (const name of schemaNames) expect(() => get(name)).not.toThrow();
  });

  it('every non-common schema has at least one valid example', () => {
    const covered = new Set(load('valid').map((e) => schemaFor(e.file)));
    const uncovered = schemaNames.filter((n) => !covered.has(n));
    expect(uncovered).toEqual([]);
  });

  for (const { file, data } of load('valid')) {
    it(`accepts valid example ${file}`, () => {
      const validate = get(schemaFor(file));
      expect(validate(data), JSON.stringify(validate.errors)).toBe(true);
    });
  }

  for (const { file, data } of load('invalid')) {
    it(`rejects invalid example ${file}`, () => {
      expect(get(schemaFor(file))(data)).toBe(false);
    });
  }
});

describe('command semantics', () => {
  const command = get('device.command');
  const base = { commandId: '01J9ZQ3K8M4N5P6Q7R8S9T0V2X', expiresAt: 1790000030 };

  it('each command type is accepted with its own payload only', () => {
    expect(command({ ...base, type: 'PING', payload: {} })).toBe(true);
    expect(command({ ...base, type: 'SYNC', payload: {} })).toBe(true);
    expect(command({ ...base, type: 'PING', payload: { sessionId: 'x' } })).toBe(false);
  });

  it('OTA requires a lowercase sha256', () => {
    const payload = { version: '1.1.0', sha256: 'a'.repeat(64), size: 1024 };
    expect(command({ ...base, type: 'OTA', payload })).toBe(true);
    expect(command({ ...base, type: 'OTA', payload: { ...payload, sha256: 'A'.repeat(64) } })).toBe(false);
  });

  it('money is integer only', () => {
    expect(get('common.MoneyUzs')(20000)).toBe(true);
    expect(get('common.MoneyUzs')(20000.5)).toBe(false);
    expect(get('common.MoneyUzs')(-1)).toBe(false);
  });
});

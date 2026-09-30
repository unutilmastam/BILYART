import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import Ajv, { type ValidateFunction } from 'ajv';

const schemaDir = join(dirname(fileURLToPath(import.meta.url)), '..', 'schemas');
const BASE = 'https://protocol.bilyart/';

/** Builds an Ajv instance with every protocol schema registered (Node-only; used by tests and tooling). */
export function createValidator(): { ajv: Ajv; schemaIds: string[]; get(name: string): ValidateFunction } {
  const ajv = new Ajv({ allErrors: true, strict: true, strictTuples: false });
  const schemaIds: string[] = [];
  for (const file of readdirSync(schemaDir).filter((f) => f.endsWith('.schema.json'))) {
    const schema = JSON.parse(readFileSync(join(schemaDir, file), 'utf8'));
    ajv.addSchema(schema);
    schemaIds.push(schema.$id);
  }
  return {
    ajv,
    schemaIds,
    get(name: string): ValidateFunction {
      const ref = name.startsWith('common.')
        ? `${BASE}common.schema.json#/definitions/${name.slice('common.'.length)}`
        : `${BASE}${name}.schema.json`;
      const fn = ajv.getSchema(ref);
      if (!fn) throw new Error(`Unknown schema: ${name}`);
      return fn;
    },
  };
}

// Generates src/generated/protocol.ts from schemas/*.schema.json.
// Usage: node scripts/generate.mjs [--check]   (--check fails if the committed file is stale)
import { readFileSync, readdirSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { compile } from 'json-schema-to-typescript';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const schemaDir = join(root, 'schemas');
const outFile = join(root, 'src', 'generated', 'protocol.ts');

const files = readdirSync(schemaDir).filter((f) => f.endsWith('.schema.json')).sort();
const definitions = {};
for (const file of files) {
  const schema = JSON.parse(readFileSync(join(schemaDir, file), 'utf8'));
  if (file === 'common.schema.json') {
    for (const name of Object.keys(schema.definitions)) {
      definitions[name] = { $ref: `${file}#/definitions/${name}` };
    }
    continue;
  }
  definitions[schema.title] = { $ref: file };
}

const wrapper = {
  $schema: 'http://json-schema.org/draft-07/schema#',
  title: 'ProtocolRoot',
  type: 'object',
  additionalProperties: false,
  definitions,
};

let ts = await compile(wrapper, 'ProtocolRoot', {
  cwd: schemaDir,
  bannerComment:
    '/* eslint-disable */\n/**\n * GENERATED FILE — do not edit. Source: packages/protocol/schemas/*.schema.json\n * Regenerate: npm run generate (in packages/protocol)\n */',
  unreachableDefinitions: true,
  ignoreMinAndMaxItems: true,
  additionalProperties: false,
  strictIndexSignatures: true,
  format: true,
  style: { singleQuote: true, printWidth: 110 },
});
// The wrapper itself carries no data.
ts = ts.replace(/export interface ProtocolRoot \{\}\n\n?/, '');

if (process.argv.includes('--check')) {
  let current = '';
  try {
    current = readFileSync(outFile, 'utf8');
  } catch {
    // missing file is stale
  }
  if (current !== ts) {
    console.error('src/generated/protocol.ts is stale. Run: npm run generate');
    process.exit(1);
  }
  console.log('generated types are up to date');
} else {
  mkdirSync(dirname(outFile), { recursive: true });
  writeFileSync(outFile, ts);
  console.log(`wrote ${outFile}`);
}

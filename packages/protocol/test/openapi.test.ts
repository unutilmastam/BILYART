import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import SwaggerParser from '@apidevtools/swagger-parser';
import { describe, expect, it } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const spec = join(here, '..', '..', '..', 'docs', 'openapi.yaml');
const BASE = 'https://protocol.bilyart/';

// Schemas declare $id under a non-resolvable base; map it to the local files (never fetched from the network).
const protocolResolver = {
  order: 1,
  canRead: (file: { url: string }) => file.url.startsWith(BASE),
  read: (file: { url: string }) => readFileSync(join(here, '..', 'schemas', file.url.slice(BASE.length)), 'utf8'),
};

describe('docs/openapi.yaml', () => {
  it('is a valid OpenAPI document whose protocol $refs resolve', async () => {
    const api = await SwaggerParser.validate(spec, { resolve: { protocol: protocolResolver } as never });
    expect(Object.keys(api.paths ?? {})).toContain('/device/v1/poll');
  });
});

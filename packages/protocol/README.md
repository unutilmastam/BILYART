# packages/protocol

Single source of truth for tablet and device message formats.

| path | what |
|---|---|
| `schemas/*.schema.json` | JSON Schema (draft-07). `device.*` = ESP32 ↔ `/device/v1`, `tablet.*` = kiosk ↔ `/api/tablet`, `common` = shared types + `ApiError` |
| `src/generated/protocol.ts` | TypeScript types generated from the schemas — **do not edit** |
| `src/validator.ts` | Ajv validator with all schemas registered (Node tooling/tests) |
| `examples/valid`, `examples/invalid` | Example messages; tests assert each is accepted/rejected by its schema (file name prefix = schema name) |

Consumers: `apps/tablet` (types), `apps/api` (contract tests validate real responses against the schemas), `devices/esp32` (field names/limits mirrored in firmware, checked in Phase 11).

```
npm ci
npm run generate          # after editing schemas
npm test && npm run typecheck && npm run check-generated
```

Rules: tenant/branch/table fields are never part of device/tablet requests (`additionalProperties: false` rejects them). Money is integer UZS. Device times are epoch seconds; tablet times are ISO-8601 UTC.

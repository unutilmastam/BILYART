# apps/web-admin

Super Admin + Client Admin **PWA** (React 19, Vite 8, TypeScript, TanStack Query, React Router 8, Tailwind 4). Mobile-first: the owner uses a phone/iPad.

- Served by Laravel under **`/admin/`** (same origin as `/api` → session cookie + CSRF, no CORS). CI builds `dist/`, deploy copies it to `apps/api/public/admin/`.
- The service worker caches only the app shell; API data is always live (never cached).
- UI strings: `src/i18n/uz.ts` (keys ready for `ru`).
- All authorization is enforced by the API; route guards here are UX only.

| path | what |
|---|---|
| `src/lib/api.ts` | fetch client: CSRF, Idempotency-Key on writes, `ApiError {code, message, fields}` |
| `src/lib/format.ts` | integer UZS formatting, Tashkent dates |
| `src/features/super/api.ts` | Super Admin queries/mutations |
| `src/pages/super/*` | dashboard, clients (list/create/detail + actions), payments, audit, settings |
| `src/pages/client/*` | client area (Phase 6 grows it) |

```
npm ci
npm run dev        # http://localhost:5173/admin/ (proxies /api to 127.0.0.1:8000)
npm test && npm run typecheck && npm run build
```

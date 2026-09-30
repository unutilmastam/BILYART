# apps/tablet

Customer kiosk PWA (React + Vite + TypeScript), served by Laravel under `/tablet/`. Setup for the hall: [docs/TABLET_SETUP.md](../../docs/TABLET_SETUP.md).

- Flow: pairing code → tables → duration → price → front camera (MediaPipe face **detection**, one JPEG) → start → countdown.
- Server time: `src/lib/clock.ts` (offset from `serverTime` samples, lowest round trip wins). Countdowns and the 5-minute warning run locally from `endAt`.
- Offline: last bootstrap cached in IndexedDB for display only; starting a session needs the server.
- Protocol types: `@bilyart/protocol` (alias to `packages/protocol/src`).
- Face model `public/models/blaze_face_short_range.tflite` (Apache-2.0, sha256 checked in CI) and the MediaPipe WASM runtime ship with the build — no CDN.

```
npm ci && npm test && npm run typecheck && npm run build   # dist/ → apps/api/public/tablet
```

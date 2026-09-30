/// <reference types="vitest/config" />
import { copyFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';

const protocol = fileURLToPath(new URL('../../packages/protocol/src/index.ts', import.meta.url));

/** Ships the MediaPipe WASM runtime with the build (served from our own origin, never a CDN). */
function mediapipeWasm(): Plugin {
  const files = ['vision_wasm_internal.js', 'vision_wasm_internal.wasm', 'vision_wasm_nosimd_internal.js', 'vision_wasm_nosimd_internal.wasm'];
  return {
    name: 'bilyart-mediapipe-wasm',
    apply: 'build',
    closeBundle() {
      const from = fileURLToPath(new URL('./node_modules/@mediapipe/tasks-vision/wasm/', import.meta.url));
      const to = fileURLToPath(new URL('./dist/mediapipe/', import.meta.url));
      mkdirSync(to, { recursive: true });
      for (const f of files) copyFileSync(from + f, to + f);
    },
  };
}

// Served by Laravel under /tablet (same origin as /api → no CORS).
export default defineConfig({
  base: '/tablet/',
  resolve: { alias: { '@bilyart/protocol': protocol } },
  plugins: [
    react(),
    tailwindcss(),
    mediapipeWasm(),
    VitePWA({
      registerType: 'autoUpdate',
      injectRegister: 'auto',
      manifest: {
        name: 'Bilyart stol band qilish',
        short_name: 'Bilyart',
        description: "Billiard stolini band qilish kioski",
        lang: 'uz',
        start_url: '/tablet/',
        scope: '/tablet/',
        display: 'fullscreen',
        orientation: 'landscape',
        background_color: '#0f172a',
        theme_color: '#0f172a',
        icons: [
          { src: 'icon.svg', sizes: 'any', type: 'image/svg+xml', purpose: 'any' },
          { src: 'icon.svg', sizes: 'any', type: 'image/svg+xml', purpose: 'maskable' },
        ],
      },
      workbox: {
        // App shell + face model precached; the ~11 MB WASM is cached on first use. API data is never cached here
        // (the app keeps its own read-only IndexedDB cache for offline display).
        globPatterns: ['**/*.{js,css,html,svg,tflite,webmanifest}'],
        globIgnores: ['mediapipe/**'],
        navigateFallback: '/tablet/index.html',
        navigateFallbackDenylist: [/^\/api\//, /^\/device\//, /^\/admin\//],
        runtimeCaching: [
          { urlPattern: /\/tablet\/mediapipe\//, handler: 'CacheFirst', options: { cacheName: 'mediapipe-wasm', expiration: { maxEntries: 8 } } },
        ],
      },
    }),
  ],
  server: {
    port: 5174,
    proxy: { '/api': { target: 'http://127.0.0.1:8000', changeOrigin: false } },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    css: false,
  },
});

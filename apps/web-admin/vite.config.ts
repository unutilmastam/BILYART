/// <reference types="vitest/config" />
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';

// Served by Laravel under /admin (same origin as /api → cookie auth, no CORS).
export default defineConfig({
  base: '/admin/',
  plugins: [
    react(),
    tailwindcss(),
    VitePWA({
      registerType: 'autoUpdate',
      injectRegister: 'auto',
      manifest: {
        name: 'Bilyart boshqaruv paneli',
        short_name: 'Bilyart',
        description: 'Billiard zallarini boshqarish paneli',
        lang: 'uz',
        start_url: '/admin/',
        scope: '/admin/',
        display: 'standalone',
        background_color: '#032a22',
        theme_color: '#032a22',
        icons: [
          { src: 'icon.svg', sizes: 'any', type: 'image/svg+xml', purpose: 'any' },
          { src: 'icon-maskable.svg', sizes: 'any', type: 'image/svg+xml', purpose: 'maskable' },
        ],
      },
      workbox: {
        // App shell only. API responses are never cached by the service worker (always live data).
        navigateFallback: '/admin/index.html',
        navigateFallbackDenylist: [/^\/api\//, /^\/device\//, /^\/tablet\//],
        runtimeCaching: [],
      },
    }),
  ],
  server: {
    port: 5173,
    proxy: {
      '/api': { target: 'http://127.0.0.1:8000', changeOrigin: false },
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    css: false,
  },
});

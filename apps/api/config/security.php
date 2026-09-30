<?php

/*
| HTTP hardening (docs/SECURITY.md §9). The same values are repeated in the
| .htaccess files for static files Apache serves without PHP; a test keeps
| them in sync.
*/
return [
    'csp' => [
        // JSON / non-HTML responses: nothing may be loaded or framed.
        'api' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
        // Admin PWA: only own bundled scripts/styles; no inline script, no eval, no third parties.
        'admin' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; manifest-src 'self'; worker-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
        // Tablet kiosk PWA: same, plus the local MediaPipe WASM runtime ('wasm-unsafe-eval' only allows compiling
        // WebAssembly, not JS eval), camera stream (media-src blob:) and its worker.
        'tablet' => "default-src 'self'; script-src 'self' 'wasm-unsafe-eval'; style-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; font-src 'self'; connect-src 'self'; manifest-src 'self'; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
    ],
    'permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), serial=(), bluetooth=()',
    // The front camera is allowed for the tablet kiosk path only (one photo per session).
    'permissions_policy_tablet' => 'camera=(self), microphone=(), geolocation=(), payment=(), usb=(), serial=(), bluetooth=()',
    'hsts' => 'max-age=31536000; includeSubDomains',
];

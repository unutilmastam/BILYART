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
    ],
    // The tablet PWA (Phase 8) gets camera=(self) on its own path only.
    'permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), serial=(), bluetooth=()',
    'hsts' => 'max-age=31536000; includeSubDomains',
];

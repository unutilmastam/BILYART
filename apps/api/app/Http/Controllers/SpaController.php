<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/** Serves a PWA shell for deep links (e.g. /admin/super/clients). Static assets never reach PHP. */
final class SpaController extends Controller
{
    public function admin(): Response
    {
        return $this->shell(public_path('admin/index.html'), config('security.csp.admin'));
    }

    private function shell(string $file, string $csp): Response
    {
        abort_unless(is_file($file), 404);

        return response((string) file_get_contents($file), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            // The shell must always be revalidated so a new release is picked up immediately.
            'Cache-Control' => 'no-cache, private',
            'Content-Security-Policy' => $csp,
        ]);
    }
}

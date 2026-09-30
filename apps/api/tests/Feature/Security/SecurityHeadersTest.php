<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** SECURITY.md §9: headers on PHP responses, and the .htaccess copies for static files stay identical. */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function json_responses_carry_the_locked_down_headers(): void
    {
        $res = $this->getJson('http://localhost/api/me')->assertStatus(401);

        $res->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'")
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Permissions-Policy', config('security.permissions_policy'))
            ->assertHeaderMissing('X-Powered-By')
            ->assertHeaderMissing('Strict-Transport-Security');
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('camera=()', config('security.permissions_policy'));
    }

    #[Test]
    public function hsts_is_sent_over_https(): void
    {
        $this->getJson('https://localhost/api/me')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    #[Test]
    public function the_admin_shell_gets_the_strict_spa_policy(): void
    {
        $dir = public_path('admin');
        $created = ! is_dir($dir);
        @mkdir($dir, 0775, true);
        $existing = is_file("$dir/index.html") ? file_get_contents("$dir/index.html") : null;
        file_put_contents("$dir/index.html", '<!doctype html><title>shell</title>');

        try {
            $csp = $this->get('/admin/sessions')->assertOk()->headers->get('Content-Security-Policy');
        } finally {
            $existing === null ? unlink("$dir/index.html") : file_put_contents("$dir/index.html", $existing);
            if ($created) {
                rmdir($dir);
            }
        }

        $this->assertSame(config('security.csp.admin'), $csp);
        foreach (["script-src 'self'", "frame-ancestors 'none'", "object-src 'none'", "connect-src 'self'"] as $directive) {
            $this->assertStringContainsString($directive, $csp);
        }
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
    }

    #[Test]
    public function only_the_tablet_shell_may_use_the_camera_and_wasm(): void
    {
        $dir = public_path('tablet');
        $created = ! is_dir($dir);
        @mkdir($dir, 0775, true);
        $existing = is_file("$dir/index.html") ? file_get_contents("$dir/index.html") : null;
        file_put_contents("$dir/index.html", '<!doctype html><title>kiosk</title>');

        try {
            $res = $this->get('/tablet/')->assertOk()->assertSee('kiosk', false);
        } finally {
            $existing === null ? unlink("$dir/index.html") : file_put_contents("$dir/index.html", $existing);
            if ($created) {
                rmdir($dir);
            }
        }

        $res->assertHeader('Content-Security-Policy', config('security.csp.tablet'))
            ->assertHeader('Permissions-Policy', config('security.permissions_policy_tablet'));
        $this->assertStringContainsString('camera=(self)', config('security.permissions_policy_tablet'));
        $this->assertStringNotContainsString("'unsafe-eval'", config('security.csp.tablet'));
        $this->assertStringNotContainsString('unsafe-inline', config('security.csp.tablet'));
        // Everything else keeps the camera off.
        $this->getJson('http://localhost/api/me')->assertHeader('Permissions-Policy', config('security.permissions_policy'));
    }

    #[Test]
    public function htaccess_copies_match_the_php_values(): void
    {
        $root = (string) file_get_contents(public_path('.htaccess'));
        $admin = (string) file_get_contents(base_path('../web-admin/public/.htaccess'));

        $tablet = (string) file_get_contents(base_path('../tablet/public/.htaccess'));
        $this->assertStringContainsString('Header always set Permissions-Policy "'.config('security.permissions_policy').'" env=!BILYART_TABLET', $root);
        $this->assertStringContainsString('Header always set Content-Security-Policy "'.config('security.csp.tablet').'"', $tablet);
        $this->assertStringContainsString('Header always set Permissions-Policy "'.config('security.permissions_policy_tablet').'"', $tablet);
        $this->assertStringContainsString('Header always set Strict-Transport-Security "'.config('security.hsts').'" env=HTTPS', $root);
        $this->assertStringContainsString('Header always set Content-Security-Policy "'.config('security.csp.admin').'"', $admin);
        // HTTPS redirect, dotfile block, no directory listing.
        $this->assertStringContainsString('RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]', $root);
        $this->assertStringContainsString('RewriteRule (^|/)\.(?!well-known/) - [F,L]', $root);
        $this->assertStringContainsString('Options -Indexes', $root);
    }
}

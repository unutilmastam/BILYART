<?php

namespace Tests\Feature\Http;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpaShellTest extends TestCase
{
    #[Test]
    public function admin_deep_links_return_the_pwa_shell(): void
    {
        $dir = public_path('admin');
        $created = ! is_dir($dir);
        @mkdir($dir, 0775, true);
        $existing = is_file("$dir/index.html") ? file_get_contents("$dir/index.html") : null;
        file_put_contents("$dir/index.html", '<!doctype html><title>shell</title>');

        try {
            $this->get('/admin/super/clients/01ABC')->assertOk()->assertSee('shell', false)->assertHeader('X-Frame-Options', 'DENY');
            $this->get('/admin')->assertOk();
        } finally {
            $existing === null ? unlink("$dir/index.html") : file_put_contents("$dir/index.html", $existing);
            if ($created) {
                rmdir($dir);
            }
        }
    }

    #[Test]
    public function the_bare_domain_redirects_to_the_admin_panel(): void
    {
        $this->get('/')->assertRedirect('/admin/');
    }

    #[Test]
    public function without_a_build_the_admin_path_is_a_plain_404(): void
    {
        if (is_file(public_path('admin/index.html'))) {
            $this->markTestSkipped('A web-admin build is present locally.');
        }
        $this->get('/admin/anything')->assertNotFound();
    }
}

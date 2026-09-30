<?php

namespace Tests\Feature\Http;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** spec §50 / SECURITY.md §8: one JSON error shape, no stack traces, security headers. */
class ErrorShapeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function unknown_routes_return_the_json_error_shape(): void
    {
        $this->getJson('/api/nope')->assertStatus(404)
            ->assertExactJsonStructure(['error' => ['code', 'message', 'requestId']])
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function unexpected_exceptions_never_expose_internals(): void
    {
        config(['app.debug' => true]); // even with debug on, the API hides internals
        Route::get('/_test/crash', fn () => throw new \RuntimeException('SQLSTATE secret internals at /home/app'));

        $response = $this->getJson('/_test/crash')->assertStatus(500)->assertJsonPath('error.code', 'SERVER_ERROR');

        $body = $response->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('/home/app', $body);
        $this->assertStringNotContainsString('trace', $body);
        $this->assertSame("Nimadir xato ketdi. Qayta urinib ko'ring.", $response->json('error.message'));
    }

    #[Test]
    public function validation_errors_list_fields(): void
    {
        $this->postJson('/api/auth/login', [])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['fields' => ['login', 'password']]]);
    }

    #[Test]
    public function responses_carry_request_id_and_security_headers(): void
    {
        $this->getJson('/api/auth/csrf', ['X-Request-Id' => 'abcdef12-3456'])
            ->assertNoContent()
            ->assertHeader('X-Request-Id', 'abcdef12-3456')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertCookie('XSRF-TOKEN');

        $bad = $this->getJson('/api/auth/csrf', ['X-Request-Id' => '<script>'])->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $bad);
    }

    #[Test]
    public function admin_routes_are_protected_by_session_and_csrf_middleware(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($r) => $r->uri() === 'api/admin/branches');
        $this->assertContains('web', $route->gatherMiddleware());

        $web = app(Kernel::class)->getMiddlewareGroups()['web'];
        $this->assertContains(PreventRequestForgery::class, $web);
        $this->assertContains(StartSession::class, $web);
    }

    #[Test]
    public function private_storage_is_never_served_over_http(): void
    {
        $this->get('/storage/tenants/1/sessions/x/photo.jpg')->assertStatus(404);
    }
}

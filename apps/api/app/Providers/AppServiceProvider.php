<?php

namespace App\Providers;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Auth\TenantAgnosticUserProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One context per request / queued job (scoped instances are reset between them).
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        Auth::provider('tenant_agnostic', fn ($app, array $config) => new TenantAgnosticUserProvider($app['hash'], $config['model']));
    }
}

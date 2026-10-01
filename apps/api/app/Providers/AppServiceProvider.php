<?php

namespace App\Providers;

use App\Domain\Auth\CurrentPrincipal;
use App\Domain\Backups\BackupCrypto;
use App\Domain\Photos\Storage\LocalPrivateDisk;
use App\Domain\Photos\Storage\PhotoStorage;
use App\Domain\Telegram\Client\HttpTelegramClient;
use App\Domain\Telegram\Client\TelegramClient;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Auth\TenantAgnosticUserProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One context/principal per request or queued job (scoped instances are reset between them).
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(CurrentPrincipal::class);

        // Telegram Bot API adapter (real HTTP; tests fake the HTTP layer).
        $this->app->bind(TelegramClient::class, HttpTelegramClient::class);

        $this->app->bind(BackupCrypto::class, fn () => BackupCrypto::fromConfig());

        // Photo storage adapter (spec §22). v1: private local disk on the hosting.
        $this->app->bind(PhotoStorage::class, LocalPrivateDisk::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        Auth::provider('tenant_agnostic', fn ($app, array $config) => new TenantAgnosticUserProvider($app['hash'], $config['model']));

        $this->configureRateLimits();
    }

    /** SECURITY.md §5. */
    private function configureRateLimits(): void
    {
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:'.$request->ip().'|'.strtolower((string) $request->input('login'))),
            Limit::perMinute(20)->by('login-ip:'.$request->ip()),
        ]);
        RateLimiter::for('pairing', fn (Request $request) => Limit::perMinutes(15, 5)->by('pair:'.($request->user()?->tenant_id ?? $request->ip())));
        RateLimiter::for('device-register', fn (Request $request) => Limit::perHour(10)->by('dev-reg:'.$request->ip()));
        RateLimiter::for('tablet-register', fn (Request $request) => Limit::perHour(10)->by('tab-reg:'.$request->ip()));
        RateLimiter::for('tablet', fn (Request $request) => Limit::perMinute(120)->by('tablet:'.($request->bearerToken() ? hash('sha256', $request->bearerToken()) : $request->ip())));
        RateLimiter::for('photo-upload', fn (Request $request) => Limit::perMinute(10)->by('photo:'.($request->bearerToken() ? hash('sha256', $request->bearerToken()) : $request->ip())));
        RateLimiter::for('device', fn (Request $request) => Limit::perMinute(40)->by('device:'.sha1((string) $request->header('Authorization')).$request->ip()));
        RateLimiter::for('telegram', fn (Request $request) => Limit::perMinute(120)->by('tg:'.$request->route('integration')));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perHour(20)->by('upload:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('admin', fn (Request $request) => Limit::perMinute(300)->by('admin:'.($request->user()?->id ?? $request->ip())));
    }
}

<?php

namespace Workbench\App\Providers;

use Chr15k\LegacyBridge\Http\Middleware\LegacySessionBridge;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

final class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(Router $router): void
    {
        $this->app->afterResolving(EncryptCookies::class, function (EncryptCookies $middleware) {
            $middleware->disableFor(env('LEGACY_BRIDGE_COOKIE', 'PHPSESSID'));
        });
        $router->prependMiddlewareToGroup('web', LegacySessionBridge::class);
    }
}

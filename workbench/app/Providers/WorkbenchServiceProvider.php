<?php

namespace Workbench\App\Providers;

use Chr15k\LegacyBridge\Events\LegacySessionBridged;
use Chr15k\LegacyBridge\Events\LegacySessionBridgeFailed;
use Chr15k\LegacyBridge\Http\Middleware\LegacySessionBridge;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Listeners\LogSessionBridgeFailure;
use Workbench\App\Listeners\LogSessionBridgeSuccess;

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
        $router->pushMiddlewareToGroup('web', LegacySessionBridge::class);

        Event::listen(
            LegacySessionBridged::class,
            LogSessionBridgeSuccess::class
        );
        Event::listen(
            LegacySessionBridgeFailed::class,
            LogSessionBridgeFailure::class
        );
    }
}

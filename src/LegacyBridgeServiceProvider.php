<?php

namespace Chr15k\LegacyBridge;

use Chr15k\LegacyBridge\Console\Commands\InstallCommand;
use Chr15k\LegacyBridge\Console\Commands\VerifyCommand;
use Chr15k\LegacyBridge\Contracts\LegacyContextResolver;
use Chr15k\LegacyBridge\Contracts\LegacyUserResolver;
use Chr15k\LegacyBridge\Http\Middleware\LegacySessionBridge;
use Chr15k\LegacyBridge\Payload\PayloadDecoder;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Override;

final class LegacyBridgeServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/legacy-bridge.php',
            'legacy-bridge',
        );

        $this->app->singleton(PayloadDecoder::class);

        $this->app->singleton(ResolverManager::class);

        $this->app->singleton(LegacyUserResolver::class, fn (Container $app) => $app->make(ResolverManager::class)->make());

        $this->app->singleton(LegacySessionBridge::class, fn (Container $app): LegacySessionBridge => new LegacySessionBridge(
            auth: $app->make(Factory::class),
            decoder: $app->make(PayloadDecoder::class),
            resolver: $app->make(LegacyUserResolver::class),
            contextResolver: $app->bound(LegacyContextResolver::class)
                ? $app->make(LegacyContextResolver::class)
                : null,
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/legacy-bridge.php' => config_path('legacy-bridge.php'),
            ], 'legacy-bridge-config');

            $this->publishes([
                __DIR__.'/../stubs/LegacyUserResolver.stub' => app_path('Bridge/LegacyUserResolver.php'),
            ], 'legacy-bridge-stubs');

            $this->commands([
                InstallCommand::class,
                VerifyCommand::class,
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge;

use Chr15k\LegacyBridge\Console\Commands\InstallCommand;
use Chr15k\LegacyBridge\Console\Commands\VerifyCommand;
use Chr15k\LegacyBridge\Contracts\LegacyIntegration;
use Chr15k\LegacyBridge\Contracts\LegacyUserResolver;
use Chr15k\LegacyBridge\Http\Middleware\LegacySessionBridge;
use Chr15k\LegacyBridge\Payload\PayloadDecoder;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Pipeline...
 *
 *   Request
 *       ↓
 *   Cookie value
 *       ↓
 *   Integration
 *       ↓
 *   LegacySession DTO
 *       ↓
 *   PayloadDecoder
 *       ↓
 *   Decoded payload
 *       ↓
 *   UserResolver
 *       ↓
 *   User ID
 */
final class LegacyBridgeServiceProvider extends ServiceProvider
{
    private const string CONFIG = 'legacy-bridge';

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(sprintf('%s/../config/%s.php', __DIR__, self::CONFIG), self::CONFIG);

        $this->app->singleton(Config::class, fn (Container $app): Config => new Config($app->make(Repository::class)));

        $this->app->singleton(PayloadDecoder::class);
        $this->app->singleton(ResolverManager::class);

        $this->app->singleton(LegacyUserResolver::class, fn (Container $app) => $app->make(ResolverManager::class)->make());

        $this->app->singleton(LegacyIntegration::class, function (Container $app) {
            $config = $app->make(Config::class);

            return $app->make($config->integration());
        });

        $this->app->singleton(LegacySessionBridge::class);
    }

    public function boot(): void
    {
        $this->app->afterResolving(EncryptCookies::class, function (EncryptCookies $middleware, Config $config): void {
            $middleware->disableFor($config->cookie());
        });

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

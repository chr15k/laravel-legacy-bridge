<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Tests;

use Chr15k\LegacyBridge\Http\Middleware\LegacySessionBridge;
use Chr15k\LegacyBridge\LegacyBridgeServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use WithWorkbench;

    protected function getPackageProviders($app)
    {
        return [
            LegacyBridgeServiceProvider::class
        ];
    }

    protected function defineEnvironment($app)
    {
        tap($app['config'], function (Repository $config): void {
            $config->set('app.key', 'base64:XGLICHd7JGDHe/NF8j7pxDzdPAddQufOFJ0mE8U9V7s=');

            $config->set('database.default', 'testbench');

            $config->set('database.connections.testbench', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);

            $config->set('legacy-bridge.database.connection', 'testbench');
            $config->set('legacy-bridge.database.table', 'legacy_sessions');

            $config->set([
                'queue.batching.database' => 'testbench',
                'queue.failed.database' => 'testbench',
            ]);
        });
    }

    protected function defineRoutes($router)
    {
        Route::get('/login', fn (): string => 'login')->name('login');
        Route::get('/protected', fn (): string => 'ok')->middleware([LegacySessionBridge::class, 'auth']);
    }
}

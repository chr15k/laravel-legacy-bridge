<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Tests;

use Chr15k\LegacyBridge\Middleware\LegacySessionBridge;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use WithWorkbench;

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function defineEnvironment($app)
    {
        tap($app['config'], function (Repository $config): void {
            $config->set('database.default', 'testbench');

            $config->set('database.connections.testbench', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);

            $config->set('legacy-bridge.connection', 'testbench');
            $config->set('legacy-bridge.table', 'legacy_sessions');

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

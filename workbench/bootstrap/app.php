<?php

use Chr15k\LegacyBridge\Middleware\LegacySessionBridge;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

use function Orchestra\Testbench\default_skeleton_path;

return Application::configure(basePath: $APP_BASE_PATH ?? default_skeleton_path())
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
         $middleware->web(append: [
            LegacySessionBridge::class,
         ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

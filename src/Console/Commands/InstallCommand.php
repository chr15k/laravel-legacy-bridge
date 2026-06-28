<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Console\Commands;

use Chr15k\LegacyBridge\Middleware\LegacySessionBridge;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Prompts\Elements\Element;

use function Laravel\Prompts\callout;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;

#[Signature('legacy-bridge:install')]
#[Description('Publish the legacy-bridge config and resolver stub')]
final class InstallCommand extends Command
{
    public function handle(): int
    {
        note('laravel-legacy-bridge');

        $this->publishConfig();
        $this->publishStub();

        callout(
            label: 'Next Steps',
            content: [
                Element::numberedList([
                    'Add credentials to .env (see below)',
                    'Register the middleware in bootstrap/app.php (see below)',
                    'Implement your resolver in app/Bridge/LegacyUserResolver.php',
                    'Run: php artisan legacy-bridge:verify --session-id=YOUR_ID',
                ]),
            ],
        );

        note(implode(PHP_EOL, [
            '  # .env',
            '  LEGACY_DB_CONNECTION=legacy',
            '  LEGACY_DB_HOST=127.0.0.1',
            '  LEGACY_DB_DATABASE=your_legacy_db',
            '  LEGACY_DB_USERNAME=your_user',
            '  LEGACY_DB_PASSWORD=your_password',
            '  LEGACY_SESSION_COOKIE=PHPSESSID',
        ]));

        note(implode(PHP_EOL, [
            '  # bootstrap/app.php',
            '  ->withMiddleware(function (Middleware $middleware) {',
            '      $middleware->web(append: [',
            '          '.LegacySessionBridge::class.'::class,',
            '      ]);',
            '  })',
        ]));

        callout(
            label: 'Shared Database?',
            content: [
                'No new connection needed. Point LEGACY_DB_CONNECTION at your default connection.',
                Element::keyValueList([
                    'LEGACY_DB_CONNECTION' => 'mysql',
                    'LEGACY_SESSION_TABLE' => 'sessions  (only if the table name differs)',
                ]),
            ],
            type: 'warning',
        );

        outro('Installation complete. Run legacy-bridge:verify to confirm your setup.');

        return self::SUCCESS;
    }

    private function publishConfig(): void
    {
        $this->callSilently('vendor:publish', [
            '--tag'   => 'legacy-bridge-config',
            '--force' => false,
        ]);

        info('Config published → config/legacy-bridge.php');
    }

    private function publishStub(): void
    {
        $destination = app_path('Bridge/LegacyUserResolver.php');

        if (file_exists($destination)) {
            info('Resolver already exists → app/Bridge/LegacyUserResolver.php');

            return;
        }

        if (! is_dir(app_path('Bridge'))) {
            mkdir(app_path('Bridge'), 0755, true);
        }

        $stub = file_get_contents(__DIR__.'/../../../stubs/LegacyUserResolver.stub');
        file_put_contents($destination, $stub);

        info('Resolver stub published → app/Bridge/LegacyUserResolver.php');
    }
}

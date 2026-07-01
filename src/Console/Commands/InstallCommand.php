<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Console\Commands;

use Chr15k\LegacyBridge\Http\Middleware\LegacySessionBridge;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Prompts\Elements\Element;

use function Laravel\Prompts\callout;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[Signature('legacy-bridge:install')]
#[Description('Publish the legacy-bridge config and resolver stub')]
final class InstallCommand extends Command
{
    public function handle(): int
    {
        intro('laravel-legacy-bridge');

        $this->publishConfig();

        $sharedDb = confirm(
            label: 'Does your legacy app share the same database as your new Laravel app?',
            default: false,
        );

        $envValues = $sharedDb
            ? $this->collectSharedDbEnv()
            : $this->collectSeparateDbEnv();

        $preset = select(
            label: 'Which legacy framework are you migrating from?',
            options: [
                'none'         => 'Plain PHP / unknown',
                'laravel'      => 'Laravel',
                'codeigniter3' => 'CodeIgniter 3',
                'codeigniter4' => 'CodeIgniter 4',
                'symfony'      => 'Symfony',
            ],
            default: 'none',
        );

        $envValues = array_merge($envValues, $this->presetEnv($preset));

        $needsResolver = $this->needsCustomResolver($preset);

        if (! $needsResolver) {
            $needsResolver = confirm(
                label: 'Do you need a custom resolver to locate the user ID in your legacy session payload?',
                default: false,
                hint: 'Most common structures are handled automatically — say no if unsure.',
            );
        }

        $this->writeEnvValues($envValues);

        if ($needsResolver) {
            $this->publishStub();
        }

        $this->printMiddlewareStep();
        $this->printNextSteps($needsResolver, $preset);

        outro('Installation complete. Run php artisan legacy-bridge:verify to confirm your setup.');

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

    /**
     * @return array<string, string>
     */
    private function collectSeparateDbEnv(): array
    {
        info('Enter your legacy database credentials:');

        return [
            'LEGACY_BRIDGE_DB_CONNECTION' => 'legacy',
            'LEGACY_DB_HOST'              => text(label: 'DB host', default: '127.0.0.1'),
            'LEGACY_DB_PORT'              => text(label: 'DB port', default: '3306'),
            'LEGACY_DB_DATABASE'          => text(label: 'DB database', required: true),
            'LEGACY_DB_USERNAME'          => text(label: 'DB username', required: true),
            'LEGACY_DB_PASSWORD'          => text(label: 'DB password'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function collectSharedDbEnv(): array
    {
        return [
            'LEGACY_BRIDGE_DB_CONNECTION' => text(
                label: 'DB connection name',
                default: config('database.default', 'mysql'),
                hint: 'Must match an existing connection in config/database.php',
            ),
            'LEGACY_BRIDGE_TABLE' => text(
                label: 'Legacy sessions table name',
                default: 'sessions',
                hint: 'Only change this if the legacy table name differs from "sessions"',
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function presetEnv(string $preset): array
    {
        return match ($preset) {
            'laravel' => [
                'LEGACY_BRIDGE_COOKIE'           => 'laravel_session',
                'LEGACY_BRIDGE_PAYLOAD_FORMAT'   => 'laravel',
                'LEGACY_BRIDGE_COOKIE_ENCRYPTED' => 'laravel',
                'LEGACY_BRIDGE_APP_KEY'          => text(
                    label: 'Legacy app APP_KEY',
                    required: true,
                    hint: "Found in the legacy application's .env file",
                ),
            ],
            'codeigniter3' => [
                'LEGACY_BRIDGE_COOKIE'          => 'ci_session',
                'LEGACY_BRIDGE_TABLE'           => 'ci_sessions',
                'LEGACY_BRIDGE_PAYLOAD_FORMAT'  => 'encrypted',
                'LEGACY_BRIDGE_RESOLVER_DRIVER' => 'key',
                'LEGACY_BRIDGE_RESOLVER_KEY'    => 'user_id',
                'LEGACY_BRIDGE_APP_KEY'         => text(
                    label: 'CodeIgniter encryption_key',
                    required: true,
                    hint: 'Found in application/config/config.php',
                ),
            ],
            'codeigniter4' => [
                'LEGACY_BRIDGE_COOKIE'          => 'ci_session',
                'LEGACY_BRIDGE_TABLE'           => 'ci_sessions',
                'LEGACY_BRIDGE_PAYLOAD_FORMAT'  => 'php_session',
                'LEGACY_BRIDGE_RESOLVER_DRIVER' => 'key',
                'LEGACY_BRIDGE_RESOLVER_KEY'    => 'user_id',
            ],
            'symfony' => [
                'LEGACY_BRIDGE_COOKIE'          => 'PHPSESSID',
                'LEGACY_BRIDGE_PAYLOAD_FORMAT'  => 'php_session',
                'LEGACY_BRIDGE_RESOLVER_DRIVER' => 'custom',
            ],
            default => [
                'LEGACY_BRIDGE_COOKIE' => text(
                    label: 'Legacy session cookie name',
                    default: 'PHPSESSID',
                ),
                'LEGACY_BRIDGE_PAYLOAD_FORMAT'  => 'auto',
                'LEGACY_BRIDGE_RESOLVER_DRIVER' => 'auto',
            ],
        };
    }

    private function needsCustomResolver(string $preset): bool
    {
        return $preset === 'symfony';
    }

    /**
     * @param  array<string, string>  $values
     */
    private function writeEnvValues(array $values): void
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            return;
        }

        $env = file_get_contents($envPath);

        foreach ($values as $key => $value) {
            $line = sprintf('%s=%s', $key, $value);

            if (preg_match(sprintf('/^%s=/m', $key), $env)) {
                // Update existing key
                $env = preg_replace(sprintf('/^%s=.*/m', $key), $line, $env);
            } else {
                // Append new key
                $env .= PHP_EOL.$line;
            }
        }

        file_put_contents($envPath, $env);

        info(sprintf('.env updated (%d keys)', count($values)));
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

    private function printMiddlewareStep(): void
    {
        note(implode(PHP_EOL, [
            '  # bootstrap/app.php',
            '  ->withMiddleware(function (Middleware $middleware) {',
            '      $middleware->web(append: [',
            '          '.LegacySessionBridge::class.'::class,',
            '      ]);',
            '  })',
        ]));
    }

    private function printNextSteps(bool $needsResolver, string $preset): void
    {
        $steps = ['Register the middleware in bootstrap/app.php (see above)'];

        if ($needsResolver || $preset === 'symfony') {
            $steps[] = 'Implement your resolver in app/Bridge/LegacyUserResolver.php';
        }

        $steps[] = 'Run: php artisan legacy-bridge:verify --session-id=YOUR_ID';

        callout(
            label: 'Next Steps',
            content: [Element::numberedList($steps)],
        );
    }
}

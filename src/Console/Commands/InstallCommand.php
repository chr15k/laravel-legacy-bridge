<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Console\Commands;

use Chr15k\LegacyBridge\Middleware\LegacySessionBridge;
use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'legacy-bridge:install';

    protected $description = 'Publish the legacy-bridge config and resolver stub';

    public function handle(): int
    {
        $this->info('Installing laravel-legacy-bridge...');
        $this->newLine();

        $this->callSilently('vendor:publish', [
            '--tag'   => 'legacy-bridge-config',
            '--force' => false,
        ]);
        $this->line('  <fg=green;options=bold>✓</> Config published → <comment>config/legacy-bridge.php</comment>');

        $this->publishStub();

        $this->newLine();
        $this->warn('  Next steps:');
        $this->line('  1. Add your legacy DB credentials to <comment>.env</comment>:');
        $this->newLine();
        $this->line('     <comment>LEGACY_DB_CONNECTION=legacy</comment>');
        $this->line('     <comment>LEGACY_DB_HOST=127.0.0.1</comment>');
        $this->line('     <comment>LEGACY_DB_DATABASE=your_legacy_db</comment>');
        $this->line('     <comment>LEGACY_DB_USERNAME=your_user</comment>');
        $this->line('     <comment>LEGACY_DB_PASSWORD=your_password</comment>');
        $this->line('     <comment>LEGACY_SESSION_COOKIE=PHPSESSID</comment>');
        $this->newLine();
        $this->line('     <fg=yellow>Tip:</> If both apps share the same database, set <comment>LEGACY_DB_CONNECTION</comment>');
        $this->line('     to your default connection (e.g. <comment>mysql</comment>) and skip adding a new');
        $this->line('     connection entirely. Only set <comment>LEGACY_SESSION_TABLE</comment> if your legacy');
        $this->line('     sessions table has a different name than <comment>sessions</comment>.');
        $this->newLine();
        $this->line('  2. Register the middleware in <comment>bootstrap/app.php</comment>:');
        $this->newLine();
        $this->line('     <comment>->withMiddleware(function (Middleware $middleware) {</comment>');
        $this->line('     <comment>    $middleware->web(append: [</comment>');
        $this->line('     <comment>        '.LegacySessionBridge::class.'::class,</comment>');
        $this->line('     <comment>    ]);</comment>');
        $this->line('     <comment>})</comment>');
        $this->newLine();
        $this->line('  3. Implement your resolver in <comment>app/Bridge/LegacyUserResolver.php</comment>');
        $this->newLine();
        $this->line('  4. Verify with: <comment>php artisan legacy-bridge:verify --session-id=YOUR_ID</comment>');
        $this->newLine();

        return self::SUCCESS;
    }

    private function publishStub(): void
    {
        $destination = app_path('Bridge/LegacyUserResolver.php');

        if (file_exists($destination)) {
            $this->line('  <fg=yellow;options=bold>↷</> Resolver already exists → <comment>app/Bridge/LegacyUserResolver.php</comment>');

            return;
        }

        if (! is_dir(app_path('Bridge'))) {
            mkdir(app_path('Bridge'), 0755, true);
        }

        $stub = file_get_contents(__DIR__.'/../../../stubs/LegacyUserResolver.stub');
        file_put_contents($destination, $stub);

        $this->line('  <fg=green;options=bold>✓</> Resolver stub published → <comment>app/Bridge/LegacyUserResolver.php</comment>');
    }
}
<?php

namespace Chr15k\LegacyBridge\Console\Commands;

use Chr15k\LegacyBridge\Payload\PayloadDecoder;
use Chr15k\LegacyBridge\Resolvers\ResolverManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Override;
use Throwable;

final class VerifyCommand extends Command
{
    protected $signature = 'legacy-bridge:verify
                            {--session-id= : A real legacy session ID to test resolution against}
                            {--connection= : Override the legacy DB connection for this check}';

    protected $description = 'Verify the legacy-bridge configuration and optionally test a session ID';

    public function __construct(
        private readonly PayloadDecoder $decoder,
        private readonly ResolverManager $resolverManager,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <fg=blue;options=bold>laravel-legacy-bridge — configuration check</>');
        $this->newLine();

        $passed = true;
        $passed = $this->checkConfig() && $passed;
        $passed = $this->checkConnection() && $passed;
        $passed = $this->checkResolver() && $passed;
        $passed = $this->checkCookieAlignment() && $passed;

        if ($sessionId = $this->option('session-id')) {
            $passed = $this->testSession($sessionId) && $passed;
        }

        $this->newLine();

        if ($passed) {
            $this->line('  <fg=green;options=bold>All checks passed.</>');
            if (! $this->option('session-id')) {
                $this->line('  Run with <comment>--session-id=YOUR_ID</comment> to test a real session.');
            }
        } else {
            $this->line('  <fg=red;options=bold>Some checks failed — review the output above.</>');
        }

        $this->newLine();

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    #[Override]
    public function fail(Throwable|string|null $exception = null): void
    {
        if (is_string($exception)) {
            $this->line('  <fg=red;options=bold>✗</> '.$exception);
        } else {
            parent::fail($exception);
        }
    }

    private function checkConfig(): bool
    {
        $config = config('legacy-bridge');

        if (! $config) {
            $this->fail('Config not found. Run: <comment>php artisan legacy-bridge:install</comment>');

            return false;
        }

        $this->pass('Config found: <comment>config/legacy-bridge.php</comment>');
        $this->line(sprintf('     cookie      → <comment>%s</comment>', $config['cookie']));
        $this->line(sprintf('     connection  → <comment>%s</comment>', $config['connection']));
        $this->line(sprintf('     table       → <comment>%s</comment>', $config['table']));
        $this->line(sprintf('     format      → <comment>%s</comment>', $config['format']));
        $this->line(sprintf('     invalidation → <comment>%s</comment>', $config['invalidation']));

        return true;
    }

    private function checkConnection(): bool
    {
        $connection = $this->option('connection') ?? config('legacy-bridge.connection');
        $table = config('legacy-bridge.table');

        try {
            $count = DB::connection($connection)->table($table)->count();
            $this->pass(sprintf('Connected to <comment>%s.%s</comment> (%d sessions)', $connection, $table, $count));

            return true;
        } catch (Throwable $throwable) {
            $this->fail(sprintf('Cannot connect to <comment>%s.%s</comment>: %s', $connection, $table, $throwable->getMessage()));

            return false;
        }
    }

    private function checkResolver(): bool
    {
        try {
            $this->resolverManager->make();
            $driver = config('legacy-bridge.resolver.driver', 'auto');
            $this->pass(sprintf('Resolver ready: <comment>%s</comment>', $driver));

            return true;
        } catch (Throwable $throwable) {
            $this->fail('Resolver error: '.$throwable->getMessage());

            return false;
        }
    }

    private function checkCookieAlignment(): bool
    {
        $legacyCookie = config('legacy-bridge.cookie');
        $laravelCookie = config('session.cookie');

        if ($legacyCookie === $laravelCookie) {
            $this->warn(
                '  ⚠  Cookie collision: legacy cookie and Laravel session cookie '.
                sprintf('are both <comment>%s</comment>. ', $legacyCookie).
                'Set SESSION_COOKIE to a different value.'
            );

            return false;
        }

        $this->pass(
            sprintf('Cookie alignment OK: legacy=<comment>%s</comment> ', $legacyCookie).
            sprintf('laravel=<comment>%s</comment>', $laravelCookie)
        );

        return true;
    }

    private function testSession(string $sessionId): bool
    {
        $this->newLine();
        $this->line('  <fg=blue>Testing session ID:</> <comment>'.mb_substr($sessionId, 0, 12).'…</comment>');
        $this->newLine();

        $connection = $this->option('connection') ?? config('legacy-bridge.connection');
        $table = config('legacy-bridge.table');
        $lifetime = (int) config('legacy-bridge.lifetime', 120);

        $row = DB::connection($connection)
            ->table($table)
            ->where('id', $sessionId)
            ->first();

        if (! $row) {
            $this->fail(sprintf('Session <comment>%s</comment> not found in <comment>%s</comment>', $sessionId, $table));

            return false;
        }

        $this->pass('Session record found');

        $age = now()->diffInMinutes(now()->createFromTimestamp($row->last_activity));
        $expired = $age > $lifetime;

        if ($expired) {
            $this->warn(sprintf('  ⚠  Session is %sm old (lifetime: %dm) — it would be rejected', $age, $lifetime));
        } else {
            $this->line(sprintf('     age → <comment>%sm</comment> (within %dm lifetime)', $age, $lifetime));
        }

        // Decode
        $format = config('legacy-bridge.format', 'auto');
        $payload = $this->decoder->decode($row->payload, $format);

        $this->pass(sprintf('Payload decoded — format: <comment>%s</comment>', $payload->format()));

        if ($payload->isEmpty()) {
            $this->fail('Decoded payload is empty — check format config');

            return false;
        }

        $this->line('     keys → <comment>'.implode(', ', array_keys($payload->all())).'</comment>');

        // Resolve
        $resolver = $this->resolverManager->make();
        $userId = $resolver->resolve($payload);

        if (! $userId) {
            $this->fail('Resolver returned null — no user ID found in payload');

            return false;
        }

        $this->pass(sprintf('Resolver returned user ID: <comment>%s</comment>', $userId));

        // Confirm user exists
        $model = config('auth.providers.users.model');
        $exists = $model::find($userId) !== null;

        if ($exists) {
            $this->pass(sprintf('User <comment>%s</comment> exists in the new application', $userId));
        } else {
            $this->fail(sprintf("User <comment>%s</comment> not found in the new application's users table", $userId));

            return false;
        }

        return ! $expired;
    }

    private function pass(string $message): void
    {
        $this->line('  <fg=green;options=bold>✓</> '.$message);
    }
}

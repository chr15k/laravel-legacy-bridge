<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Console\Commands;

use Chr15k\LegacyBridge\Config;
use Chr15k\LegacyBridge\Data\LegacySession;
use Chr15k\LegacyBridge\Payload\PayloadDecoder;
use Chr15k\LegacyBridge\ResolverManager;
use Chr15k\LegacyBridge\Session\LegacyDatabaseSessionHandler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Prompts\Elements\Element;
use Override;
use Throwable;

use function Laravel\Prompts\callout;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\warning;

#[Signature('legacy-bridge:verify
    {--session-id= : A real legacy session ID to test resolution against}
    {--connection= : Override the legacy DB connection for this check}')]
#[Description('Verify the legacy-bridge configuration and optionally test a session ID')]
final class VerifyCommand extends Command
{
    public function __construct(
        private readonly PayloadDecoder $decoder,
        private readonly ResolverManager $resolverManager,
        private readonly Config $config,
        private readonly LegacyDatabaseSessionHandler $sessionHandler
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        intro('laravel-legacy-bridge — configuration check');

        $passed = $this->checkConfig()
            && $this->checkConnection()
            && $this->checkResolver()
            && $this->checkCookieAlignment();

        $sessionId = $this->option('session-id');
        if ($sessionId && is_string($sessionId)) {
            $passed = $this->testSession($sessionId) && $passed;
        }

        if ($passed) {
            $suffix = $this->option('session-id')
                ? ''
                : ' Run with --session-id=YOUR_ID to test a real session.';

            outro('All checks passed.'.$suffix);

            return self::SUCCESS;
        }

        error('Some checks failed — review the output above.');

        return self::FAILURE;
    }

    #[Override]
    public function fail(Throwable|string|null $exception = null): void
    {
        if (is_string($exception)) {
            error($exception);
        }

        parent::fail($exception);
    }

    private function checkConfig(): bool
    {
        if (! $this->config) {
            error('Config not found. Run: php artisan legacy-bridge:install');

            return false;
        }

        callout(
            label: 'Config',
            content: [
                Element::keyValueList([
                    'file'         => 'config/legacy-bridge.php',
                    'cookie'       => $this->config->cookie(),
                    'connection'   => $this->config->connection(),
                    'table'        => $this->config->table(),
                    'format'       => $this->config->format()->value,
                    'invalidation' => $this->config->invalidationStrategy()->value,
                ]),
            ],
        );

        return true;
    }

    private function checkConnection(): bool
    {
        $connection = $this->option('connection') ?? $this->config->connection();

        if (! is_string($connection)) {
            error('Connection is not configured - check config("legacy-bridge.database.connection")');

            return false;
        }

        $table = $this->config->table();

        try {
            $count = DB::connection($connection)->table($table)->count();
            info(sprintf('Connected to %s.%s (%d sessions)', $connection, $table, $count));

            return true;
        } catch (Throwable $throwable) {
            error(sprintf('Cannot connect to %s.%s: %s', $connection, $table, $throwable->getMessage()));

            return false;
        }
    }

    private function checkResolver(): bool
    {
        try {
            $this->resolverManager->make();
            info(sprintf('Resolver ready: %s', $this->config->resolver()['driver']));

            return true;
        } catch (Throwable $throwable) {
            error('Resolver error: '.$throwable->getMessage());

            return false;
        }
    }

    private function checkCookieAlignment(): bool
    {
        $legacyCookie = $this->config->cookie();
        $laravelCookie = config('session.cookie');
        if (! is_string($laravelCookie)) {
            error('Laravel session cookie not configured - check config("session.cookie")');

            return false;
        }

        if ($legacyCookie === $laravelCookie) {
            warning(sprintf(
                'Cookie collision: both legacy and Laravel are using "%s". Set SESSION_COOKIE to a different value.',
                $legacyCookie,
            ));

            return false;
        }

        info(sprintf('Cookie alignment OK: legacy=%s  laravel=%s', $legacyCookie, $laravelCookie));

        return true;
    }

    private function testSession(string $sessionId): bool
    {
        note(sprintf('Testing session ID: %s…', mb_substr($sessionId, 0, 12)));

        $connection = $this->option('connection') ?? $this->config->connection();
        $table = $this->config->table();
        $lifetime = $this->config->lifetime();

        if (! is_string($connection)) {
            error('Connection not configured - check config("legacy-bridge.database.connection")');

            return false;
        }

        $data = $this->sessionHandler->fetch(sessionId: $sessionId, includeExpired: true);

        if (! $data instanceof LegacySession) {
            error(sprintf('Session "%s" not found in %s', $sessionId, $table));

            return false;
        }

        if ($data->expired) {
            warning(sprintf('Session is %dm old (lifetime: %dm) — it would be rejected', $data->age, $lifetime));
        }

        $payload = $this->decoder->decode($data->payload, $this->config->format());

        if ($payload->isEmpty()) {
            error('Decoded payload is empty — check format config');

            return false;
        }

        $userId = $this->resolverManager->make()->resolve($payload);

        if (! $userId) {
            error('Resolver returned null — no user ID found in payload');

            return false;
        }

        if (! $this->userExists($userId)) {
            error(sprintf("User %d not found in the new application's users table", $userId));

            return false;
        }

        callout(
            label: 'Session resolved',
            content: [
                Element::keyValueList([
                    'session_id' => mb_substr($sessionId, 0, 12).'…',
                    'format'     => $payload->format(),
                    'age'        => $data->age.'m (lifetime: '.$lifetime.'m)',
                    'keys'       => implode(', ', array_keys($payload->all())),
                    'user_id'    => (string) $userId,
                    'user_found' => 'yes',
                ]),
            ],
            type: $data->expired ? 'warning' : 'info',
        );

        return ! $data->expired;
    }

    private function userExists(int|string $userId): bool
    {
        $modelClass = config('auth.providers.users.model');

        if (! is_string($modelClass) || ! class_exists($modelClass)) {
            return false;
        }

        if (! is_subclass_of($modelClass, Model::class)) {
            return false;
        }

        return $modelClass::query()->where('id', $userId)->exists();
    }
}

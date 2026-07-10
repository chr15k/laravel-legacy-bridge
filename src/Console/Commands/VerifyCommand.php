<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Console\Commands;

use Chr15k\LegacyBridge\Data\LegacySession;
use Chr15k\LegacyBridge\LegacyBridgeResolverManager;
use Chr15k\LegacyBridge\Payload\PayloadDecoder;
use Chr15k\LegacyBridge\Session\LegacyDatabaseSessionHandler;
use Chr15k\LegacyBridge\Support\Config;
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
use function Laravel\Prompts\intro;
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
        private readonly LegacyBridgeResolverManager $resolverManager,
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
        callout(
            label: 'Config',
            content: [
                Element::keyValueList([
                    'Legacy Cookie'          => $this->config->cookie(),
                    'DB Connection'          => $this->config->connection(),
                    'DB Table'               => $this->config->table(),
                    'Payload Format'         => $this->config->format()->value,
                    'Invalidation Strategy'  => $this->config->invalidationStrategy()->value,
                    'Resolver Driver'        => $this->config->resolver()['driver'] ?? 'not configured',
                    'Session Lifetime'       => $this->config->lifetime().' minutes',
                    'Session Timezone'       => $this->config->sessionTimeZone()->getName(),
                    'Session Time Semantics' => $this->config->sessionTimeSemantics()->value,
                    'Session Time Format'    => $this->config->sessionTimeFormat()->value,
                    'Session ID Prefix'      => $this->config->sessionIdPrefix() ?: '(none)',
                    'Mapped Columns'         => implode(', ', $this->config->sessionColumns()),
                ]),
            ],
        );

        return true;
    }

    private function checkConnection(): bool
    {
        $connection = is_string($this->option('connection'))
            ? $this->option('connection')
            : $this->config->connection();

        if ($connection === '' || $connection === '0') {
            callout(
                label: 'Connection Error',
                content: [
                    Element::keyValueList([
                        'Connection' => '(not configured)',
                        'Table'      => $this->config->table(),
                    ]),
                ],
                type: 'error',
                info: 'check config("legacy-bridge.database.connection")',
            );

            return false;
        }

        $table = $this->config->table();

        try {
            $count = DB::connection($connection)->table($table)->count();
            $active = $this->sessionHandler->active();

            callout(
                label: 'Sessions',
                content: [
                    Element::keyValueList([
                        'Total Sessions'  => (string) $count,
                        'Active Sessions' => (string) $active,
                    ]),
                ],
                info: sprintf('Connected to %s.%s', $connection, $table),
            );

            return true;
        } catch (Throwable $throwable) {
            callout(
                label: 'Connection Error',
                content: [
                    Element::keyValueList([
                        'Connection' => $connection,
                        'Table'      => $table,
                        'Error'      => $throwable->getMessage(),
                    ]),
                ],
                type: 'error',
            );

            return false;
        }
    }

    private function checkResolver(): bool
    {
        try {
            $this->resolverManager->make();

            $resolver = $this->config->resolver()['driver'] ?? 'not configured';

            callout(
                label: 'Resolver',
                content: [
                    Element::keyValueList([
                        'Driver' => $resolver,
                    ]),
                ],
                info: $resolver
                    ? 'Resolver ready'
                    : '<comment>Resolver not configured</comment>',
            );

            return true;
        } catch (Throwable $throwable) {
            callout(
                label: 'Resolver Error',
                content: [
                    Element::keyValueList([
                        'Error' => $throwable->getMessage(),
                    ]),
                ],
                type: 'error',
            );

            return false;
        }
    }

    private function checkCookieAlignment(): bool
    {
        $legacyCookie = $this->config->cookie();
        $laravelCookie = config('session.cookie');

        if (! is_string($laravelCookie)) {
            callout(
                label: 'Cookie Alignment',
                content: [
                    Element::keyValueList([
                        'Legacy Cookie'  => $legacyCookie,
                        'Laravel Cookie' => '(not configured)',
                    ]),
                ],
                type: 'error',
                info: 'check config("session.cookie")',
            );

            return false;
        }

        if ($legacyCookie === $laravelCookie) {
            callout(
                label: 'Cookie Alignment',
                content: [
                    Element::keyValueList([
                        'Legacy Cookie'  => $legacyCookie,
                        'Laravel Cookie' => $laravelCookie,
                    ]),
                ],
                type: 'error',
                info: 'Cookie collision — set SESSION_COOKIE to a different value',
            );

            return false;
        }

        callout(
            label: 'Cookie Alignment',
            content: [
                Element::keyValueList([
                    'Legacy Cookie'  => $legacyCookie,
                    'Laravel Cookie' => $laravelCookie,
                ]),
            ],
            info: 'Cookie alignment OK'
        );

        return true;
    }

    private function testSession(string $sessionId): bool
    {
        $connection = is_string($this->option('connection'))
            ? $this->option('connection')
            : $this->config->connection();

        $table = $this->config->table();
        $lifetime = $this->config->lifetime();

        $data = $this->sessionHandler->fetch(sessionId: $sessionId, includeExpired: true);

        if (! $data instanceof LegacySession) {
            callout(
                label: 'Session Lookup',
                content: [
                    Element::keyValueList([
                        'Connection' => $connection,
                        'Table'      => $table,
                        'Session ID' => $sessionId,
                    ]),
                ],
                type: 'error',
                info: 'Session not found in the database',
            );

            return false;
        }

        if ($data->expired) {
            callout(
                label: 'Session Lookup',
                content: [
                    Element::keyValueList([
                        'Connection' => $connection,
                        'Table'      => $table,
                        'Session ID' => $sessionId,
                        'Age'        => $data->age.'m (lifetime: '.$lifetime.'m)',
                    ]),
                ],
                type: 'warning',
                info: 'Session is expired but otherwise valid',
            );
        }

        $payload = $this->decoder->decode($data->payload, $this->config->format());

        if ($payload->isEmpty()) {
            callout(
                label: 'Session Lookup',
                content: [
                    Element::keyValueList([
                        'Connection' => $connection,
                        'Table'      => $table,
                        'Session ID' => $sessionId,
                        'Age'        => $data->age.'m (lifetime: '.$lifetime.'m)',
                    ]),
                ],
                type: 'error',
                info: 'Decoded payload is empty — check format config',
            );

            return false;
        }

        $userId = $this->resolverManager->make()->resolve($payload);

        if ($userId === null) {
            callout(
                label: 'Session Lookup',
                content: [
                    Element::keyValueList([
                        'Connection' => $connection,
                        'Table'      => $table,
                        'Session ID' => $sessionId,
                        'Age'        => $data->age.'m (lifetime: '.$lifetime.'m)',
                    ]),
                ],
                type: 'error',
                info: 'Resolver returned null — no user ID found in payload',
            );

            return false;
        }

        if (! $this->userExists($userId)) {
            callout(
                label: 'Session Lookup',
                content: [
                    Element::keyValueList([
                        'Connection' => $connection,
                        'Table'      => $table,
                        'Session ID' => $sessionId,
                        'Age'        => $data->age.'m (lifetime: '.$lifetime.'m)',
                        'User ID'    => (string) $userId,
                    ]),
                ],
                type: 'error',
                info: 'User ID resolved from payload but not found in the users table',
            );

            return false;
        }

        callout(
            label: 'Session Resolved',
            content: [
                Element::keyValueList([
                    'Session ID' => mb_substr($sessionId, 0, 12).'…',
                    'Age'        => $data->age.'m (lifetime: '.$lifetime.'m)',
                    'Keys'       => implode(', ', array_keys($payload->all())),
                    'User ID'    => (string) $userId,
                    'User Found' => 'yes',
                ]),
            ],
            type: $data->expired ? 'warning' : 'info',
            info: $data->expired
                ? 'Session is expired but otherwise valid'
                : 'Session is valid and would be bridged',
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

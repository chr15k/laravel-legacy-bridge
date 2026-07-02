<?php

namespace Chr15k\LegacyBridge\Session;

use Chr15k\LegacyBridge\Concerns\DecryptsLegacySessionData;
use Chr15k\LegacyBridge\Config;
use Chr15k\LegacyBridge\Data\LegacySession;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;

final readonly class LegacyDatabaseSessionHandler
{
    use DecryptsLegacySessionData;

    public function __construct(private Config $config) {}

    public function resolveSessionId(string|array|null $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if ($this->config->cookieEncryption() === 'laravel') {
            return $this->decrypt(payload: $value, unserialize: false, isCookie: true);
        }

        return $value;
    }

    public function fetch(string $sessionId): ?LegacySession
    {
        $cols = $this->config->sessionColumns();

        $row = DB::connection($this->config->connection())
            ->table($this->config->table())
            ->where($cols['id'], $sessionId)
            ->where(
                $cols['last_activity'],
                '>',
                $this->resolveLastActivityThreshold($cols['last_activity_format']),
            )
            ->first();

        if ($row === null) {
            return null;
        }

        $payload = $row->{$cols['payload']};
        $activity = $this->resolveLastActivity($row->{$cols['last_activity']}, $cols['last_activity_format']);

        return new LegacySession(
            id: $row->{$cols['id']},
            userId: $row->user_id ?? null,
            ipAddress: $row->ip_address ?? null,
            userAgent: $row->user_agent ?? null,
            payload: $payload,
            lastActivity: $activity,
        );
    }

    public function invalidate(string $sessionId): void
    {
        DB::connection($this->config->connection())
            ->table($this->config->table())
            ->where('id', $sessionId)
            ->delete();

        Cookie::queue(Cookie::forget($this->config->cookie()));
    }

    private function resolveLastActivityThreshold(string $format): string|int
    {
        return $format === 'datetime'
            ? now()->subMinutes($this->config->lifetime())->toDateTimeString()
            : now()->subMinutes($this->config->lifetime())->timestamp;
    }

    private function resolveLastActivity(mixed $value, string $format): int
    {
        if ($format === 'datetime' && is_string($value)) {
            return (int) strtotime($value);
        }

        return (int) $value;
    }
}

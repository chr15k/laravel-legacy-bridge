<?php

namespace Chr15k\LegacyBridge\Session;

use Carbon\Carbon;
use Carbon\CarbonInterface;
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

    public function fetch(string $sessionId, bool $includeExpired = false): ?LegacySession
    {
        $cols = $this->config->sessionColumns();

        $query = DB::connection($this->config->connection())
            ->table($this->config->table())
            ->where($cols['id'], $sessionId);

        $threshold = $this->threshold();

        if ($includeExpired === false) {
            $query->where(
                $cols['last_activity'],
                '>',
                $this->formatForQuery(
                    $threshold,
                    $cols['last_activity_format']
                )
            );
        }

        $row = $query->first();

        if ($row === null) {
            return null;
        }

        $activity = $this->resolveLastActivity(
            $row->{$cols['last_activity']},
            $cols['last_activity_format']
        );

        return new LegacySession(
            id: $row->{$cols['id']},
            userId: $row->user_id ?? null,
            ipAddress: $row->ip_address ?? null,
            userAgent: $row->user_agent ?? null,
            payload: $row->{$cols['payload']},
            lastActivity: $activity->timestamp,
            expired: $threshold->isAfter($activity),
            age: now()->diffInMinutes($activity)
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

    private function threshold(): CarbonInterface
    {
        return now()->subMinutes($this->config->lifetime());
    }

    private function formatForQuery(CarbonInterface $carbon, string $format): int|string
    {
        return $format === 'datetime' ? $carbon->toDateTimeString() : $carbon->timestamp;
    }

    private function resolveLastActivity(string|int $value, string $format): CarbonInterface
    {
        if ($format === 'datetime' && is_string($value)) {
            return Carbon::parse($value);
        }

        return Carbon::createFromTimestamp($value);
    }
}

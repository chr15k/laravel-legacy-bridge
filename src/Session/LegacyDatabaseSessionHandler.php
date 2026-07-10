<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Session;

use Carbon\CarbonImmutable;
use Chr15k\LegacyBridge\Data\LegacySession;
use Chr15k\LegacyBridge\Enums\SessionTimeSemantics;
use Chr15k\LegacyBridge\Support\Config;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;

final readonly class LegacyDatabaseSessionHandler
{
    public function __construct(private Config $config) {}

    public function active(): int
    {
        return $this->baseQuery()
            ->where($this->config->sessionColumns()['time'], '>', $this->compareTime())
            ->count();
    }

    public function fetch(string $sessionId, bool $includeExpired = false): ?LegacySession
    {
        if (! $this->isValidSessionId($sessionId)) {
            return null;
        }

        $cols = $this->config->sessionColumns();
        $query = $this->baseQuery()->where($cols['id'], $this->config->sessionIdPrefix().$sessionId);

        if (! $includeExpired) {
            $query->where($cols['time'], '>', $this->compareTime());
        }

        $row = $query->first();

        if ($row === null) {
            return null;
        }

        $activity = $this->resolveLastActivity($row->{$cols['time']});

        if (! $activity instanceof CarbonImmutable) {
            return null;
        }

        $threshold = $this->threshold();

        return new LegacySession(
            id: $this->coerceScalar($row->{$cols['id']}) ?? 0,
            userId: $this->coerceScalar($row->user_id ?? null),
            ipAddress: is_string($row->ip_address ?? null) ? $row->ip_address : null,
            userAgent: is_string($row->user_agent ?? null) ? $row->user_agent : null,
            payload: is_string($row->{$cols['payload']}) ? $row->{$cols['payload']} : '',
            lastActivity: $activity->timestamp,
            expired: $activity->isBefore($threshold),
            age: round(abs(now()->diffInMinutes($activity))),
        );
    }

    public function invalidate(string $sessionId): void
    {
        $this->baseQuery()
            ->where(
                $this->config->sessionColumns()['id'],
                $this->config->sessionIdPrefix().$sessionId,
            )
            ->delete();

        $cookie = $this->config->cookie();

        if ($cookie !== '' && $cookie !== '0') {
            Cookie::queue(Cookie::forget($cookie));
        }
    }

    private function baseQuery(): Builder
    {
        return DB::connection($this->config->connection())
            ->table($this->config->table());
    }

    private function threshold(): CarbonImmutable
    {
        return CarbonImmutable::now()->subMinutes($this->config->lifetime());
    }

    private function compareTime(): int|float|string
    {
        $time = $this->config->sessionTimeSemantics()->representsExpires()
            ? CarbonImmutable::now()
            : $this->threshold();

        $format = $this->config->sessionTimeFormat();
        $timezone = $this->config->sessionTimeZone();

        return $format->isDatetime()
            ? $time->setTimezone($timezone)->toDateTimeString()
            : $format->toStorage($time);
    }

    private function resolveLastActivity(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $carbon = $this->resolveTime($value);
        $semantics = $this->config->sessionTimeSemantics();

        return match ($semantics) {
            SessionTimeSemantics::Activity => $carbon,
            SessionTimeSemantics::Expires  => $carbon->subMinutes($this->config->lifetime()),
        };
    }

    private function resolveTime(int|string $value): CarbonImmutable
    {
        $format = $this->config->sessionTimeFormat();
        $timezone = $this->config->sessionTimeZone();

        return $format->isTimestamp()
            ? CarbonImmutable::createFromTimestampUTC((int) $value)
            : CarbonImmutable::parse($value, $timezone)->utc();
    }

    /**
     * Reject if unreasonably long or empty, but do not prescribe a
     * specific format, as legacy systems may have different requirements.
     */
    private function isValidSessionId(string $sessionId): bool
    {
        return mb_strlen($sessionId) >= 1 && mb_strlen($sessionId) <= 256;
    }

    private function coerceScalar(mixed $value): string|int|null
    {
        return is_string($value) || is_int($value) ? $value : null;
    }
}

<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Session;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Chr15k\LegacyBridge\Concerns\DecryptsLegacySessionData;
use Chr15k\LegacyBridge\Config;
use Chr15k\LegacyBridge\Data\LegacySession;
use Chr15k\LegacyBridge\Enums\SessionTimeFormat;
use Chr15k\LegacyBridge\Enums\SessionTimeSemantics;
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

        $semantics = $this->config->sessionTimeSemantics();
        $format = $this->config->sessionTimeFormat();

        $threshold = $this->threshold();

        $query = DB::connection($this->config->connection())
            ->table($this->config->table())
            ->where($cols['id'], $sessionId);

        if (! $includeExpired) {
            $query->where(
                $cols['time'],
                '>',
                $format->toStorage(
                    $semantics->representsExpires() ? now() : $threshold,
                )
            );
        }

        $row = $query->first();

        if ($row === null) {
            return null;
        }

        $rawTime = $row->{$cols['time']};
        $activity = $this->resolveLastActivity($rawTime, $semantics, $format);

        return new LegacySession(
            id: $row->{$cols['id']},
            userId: $row->user_id ?? null,
            ipAddress: $row->ip_address ?? null,
            userAgent: $row->user_agent ?? null,
            payload: $row->{$cols['payload']},
            lastActivity: $activity->timestamp,
            expired: $activity->isBefore($threshold),
            age: now()->diffInMinutes($activity),
        );
    }

    public function invalidate(string $sessionId): void
    {
        DB::connection($this->config->connection())
            ->table($this->config->table())
            ->where($this->config->sessionColumns()['id'], $sessionId)
            ->delete();

        Cookie::queue(Cookie::forget($this->config->cookie()));
    }

    private function threshold(): CarbonInterface
    {
        return now()->subMinutes($this->config->lifetime());
    }

    private function resolveLastActivity(
        mixed $value,
        SessionTimeSemantics $semantics,
        SessionTimeFormat $format,
    ): CarbonImmutable {
        $carbon = $format->fromStorage($value);

        return match ($semantics) {
            SessionTimeSemantics::Activity => $carbon,
            // For expires semantics, back-calculate last_activity so expired/age
            // on LegacySession remain consistent regardless of which semantics is used
            SessionTimeSemantics::Expires => $carbon->subMinutes($this->config->lifetime()),
        };
    }
}

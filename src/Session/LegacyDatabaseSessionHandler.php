<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Session;

use Carbon\CarbonInterface;
use Chr15k\LegacyBridge\Data\LegacySession;
use Chr15k\LegacyBridge\Enums\SessionTimeFormat;
use Chr15k\LegacyBridge\Enums\SessionTimeSemantics;
use Chr15k\LegacyBridge\Support\Config;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;

final readonly class LegacyDatabaseSessionHandler
{
    public function __construct(private Config $config) {}

    public function fetch(string $sessionId, bool $includeExpired = false): ?LegacySession
    {
        if (! $this->isValidSessionId($sessionId)) {
            return null;
        }

        $cols = $this->config->sessionColumns();

        $semantics = $this->config->sessionTimeSemantics();
        $format = $this->config->sessionTimeFormat();

        $threshold = now()->subMinutes($this->config->lifetime());

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

        if (! $activity instanceof CarbonInterface) {
            return null;
        }

        return new LegacySession(
            id: (is_string($row->{$cols['id']}) || is_int($row->{$cols['id']}))
                ? $row->{$cols['id']} : 0,
            userId: isset($row->user_id)
                && (is_string($row->user_id) || is_int($row->user_id))
                ? $row->user_id : null,
            ipAddress: isset($row->ip_address) && is_string($row->ip_address) ? $row->ip_address : null,
            userAgent: isset($row->user_agent) && is_string($row->user_agent) ? $row->user_agent : null,
            payload: is_string($row->{$cols['payload']}) ? $row->{$cols['payload']} : '',
            lastActivity: $activity->timestamp,
            expired: $activity->isBefore($threshold),
            age: round(abs(now()->diffInMinutes($activity))),
        );
    }

    public function invalidate(string $sessionId): void
    {
        DB::connection($this->config->connection())
            ->table($this->config->table())
            ->where($this->config->sessionColumns()['id'], $sessionId)
            ->delete();

        $cookie = $this->config->cookie();

        if ($cookie !== '' && $cookie !== '0') {
            Cookie::queue(Cookie::forget($cookie));
        }
    }

    /**
     * Reject if unreasonably long or empty, but do not prescribe a
     * specific format, as legacy systems may have different requirements.
     */
    private function isValidSessionId(string $sessionId): bool
    {
        return mb_strlen($sessionId) >= 1 && mb_strlen($sessionId) <= 256;
    }

    private function resolveLastActivity(
        mixed $value,
        SessionTimeSemantics $semantics,
        SessionTimeFormat $format,
    ): ?CarbonInterface {

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $carbon = $format->fromStorage($value);

        return match ($semantics) {
            SessionTimeSemantics::Activity => $carbon,
            // For expires semantics, back-calculate last_activity so expired/age
            // on LegacySession remain consistent regardless of which semantics is used
            SessionTimeSemantics::Expires => $carbon->subMinutes($this->config->lifetime()),
        };
    }
}

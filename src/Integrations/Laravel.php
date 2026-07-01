<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Integrations;

use Chr15k\LegacyBridge\Concerns\DecryptsLegacySessionData;
use Chr15k\LegacyBridge\Config;
use Chr15k\LegacyBridge\Contracts\LegacyIntegration;
use Chr15k\LegacyBridge\Data\LegacySession;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;

final readonly class Laravel implements LegacyIntegration
{
    use DecryptsLegacySessionData;

    public function __construct(private Config $config) {}

    public function resolveSessionId(string|array|null $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if ($this->config->cookieEncryption()) {
            return $this->decrypt(payload: $value, unserialize: false, isCookie: true);
        }

        return $value;
    }

    public function fetchSessionFromStore(string $sessionId): ?LegacySession
    {
        $row = DB::connection($this->config->connection())
            ->table($this->config->table())
            ->where('id', $sessionId)
            ->where('last_activity', '>', now()->subMinutes($this->config->lifetime())->timestamp)
            ->first();

        if ($row === null) {
            return null;
        }

        return new LegacySession(
            id: $row->id,
            userId: $row->user_id,
            ipAddress: $row->ip_address,
            userAgent: $row->user_agent,
            payload: $row->payload,
            lastActivity: $row->last_activity,
        );
    }

    public function invalidateSession(string $sessionId): void
    {
        DB::connection($this->config->connection())
            ->table($this->config->table())
            ->where('id', $sessionId)
            ->delete();

        Cookie::queue(Cookie::forget($this->config->cookie()));
    }
}

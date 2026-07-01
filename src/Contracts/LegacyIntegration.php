<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Contracts;

use Chr15k\LegacyBridge\Data\LegacySession;

interface LegacyIntegration
{
    public function fetchSessionFromStore(string $sessionId): ?LegacySession;

    public function resolveSessionId(string|array|null $value): ?string;

    public function invalidateSession(string $sessionId);
}

<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Contracts;

interface LegacyIntegration
{
    // public function fetchSessionFromLegacyStore(string $sessionId): ?object;

    public function resolveSessionIdFromCookie(string|array|null $cookie): ?string;
}

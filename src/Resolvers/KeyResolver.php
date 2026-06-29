<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Resolvers;

use Chr15k\LegacyBridge\Contracts\LegacyUserResolver;
use Chr15k\LegacyBridge\Payload\LegacyPayload;

/**
 * Resolves a user ID from a dot-notation key in the payload.
 *
 * Covers the most common case: a flat or nested array where the user ID
 * lives at a known, stable key.
 *
 * Configure via: legacy-bridge.resolver.key (default: 'user_id')
 */
final readonly class KeyResolver implements LegacyUserResolver
{
    public function __construct(
        private string $key = 'user_id',
    ) {}

    public function resolve(LegacyPayload $payload): ?int
    {
        return $payload->resolveId($this->key);
    }
}

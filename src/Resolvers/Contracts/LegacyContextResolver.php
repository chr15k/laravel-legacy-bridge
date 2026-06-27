<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Resolvers\Contracts;

use Chr15k\LegacyBridge\Payload\LegacyPayload;

interface LegacyContextResolver
{
    /**
     * Resolve additional session key/value pairs to carry into Laravel's
     * session after identity is resolved.
     *
     * Return an associative array of keys and values to merge. Return an
     * empty array to carry nothing beyond the authenticated user.
     *
     * Example implementation:
     *
     *   public function resolve(?int $userId, LegacyPayload $payload): array
     *   {
     *       return $payload->only(['locale', 'timezone', 'cart_id']);
     *   }
     */
    public function resolve(?int $userId, LegacyPayload $payload): array;
}

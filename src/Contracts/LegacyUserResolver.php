<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Contracts;

use Chr15k\LegacyBridge\Payload\LegacyPayload;

interface LegacyUserResolver
{
    /**
     * Resolve a user ID from the decoded legacy session payload.
     *
     * Return the integer user ID that should be authenticated in the new
     * application, or null if the session does not represent an
     * authenticated user.
     *
     * The payload provides dot-notation access to nested structures and
     * safe resolution of object/array IDs — see LegacyPayload for the
     * full API.
     *
     * Example implementation:
     *
     *   public function resolve(LegacyPayload $payload): ?int
     *   {
     *       return $payload->resolveId('auth.user.id')
     *           ?? $payload->resolveId('user_id');
     *   }
     */
    public function resolve(LegacyPayload $payload): ?int;
}

<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Resolvers;

use Chr15k\LegacyBridge\Contracts\LegacyUserResolver;
use Chr15k\LegacyBridge\Payload\LegacyPayload;

/**
 * Tries a sequence of known payload patterns and returns the first match.
 *
 * Covers plain PHP, older Laravel/Illuminate apps, Cartalyst Sentinel,
 * and Cartalyst Sentry without any configuration. Use this as a starting
 * point and switch to a more specific driver once you know your format.
 */
final class AutoResolver implements LegacyUserResolver
{
    /**
     * Dot-notation paths tried in order. The first non-null result wins.
     */
    private const array CANDIDATE_PATHS = [
        // Plain PHP / custom apps
        'user_id',
        'userId',

        // Laravel-style auth session keys (various guard names)
        'login_web_',     // prefix; handled below
        'auth.user.id',
        'auth.id',
        'auth_user.id',
        'auth_user',

        // Cartalyst Sentinel
        'cartalyst_sentinel',
        'sentinel.user.id',

        // Cartalyst Sentry
        'cartalyst_sentry',
        'sentry.user.id',

        // Generic fallbacks
        'user.id',
        'current_user.id',
        'logged_in_user_id',
    ];

    public function resolve(LegacyPayload $payload): int|string|null
    {
        // Special-case: Laravel login_{guard}_{hash} keys — scan the
        // top-level array for any key that looks like a login key and
        // treat its value directly as the user ID.
        foreach ($payload->all() as $key => $value) {
            if (str_starts_with((string) $key, 'login_') && is_int($value)) {
                return $value;
            }
        }

        foreach (self::CANDIDATE_PATHS as $path) {
            if (str_ends_with($path, '_')) {
                continue; // already handled above
            }

            $resolved = $payload->resolveId($path);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }
}

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Legacy Session Cookie
    |--------------------------------------------------------------------------
    |
    | The name of the cookie your legacy application sets. This is used by
    | the middleware to find an existing session to bridge from.
    |
    */

    'cookie' => env('LEGACY_BRIDGE_COOKIE', 'PHPSESSID'),

    /*
    |--------------------------------------------------------------------------
    | Legacy Database Connection
    |--------------------------------------------------------------------------
    |
    | The database connection name (from config/database.php) that points to
    | your legacy application's database. Set to null if both applications
    | share the same database connection.
    |
    */

    'connection' => env('LEGACY_BRIDGE_DB_CONNECTION', 'legacy'),

    /*
    |--------------------------------------------------------------------------
    | Legacy Sessions Table
    |--------------------------------------------------------------------------
    |
    | The table name in the legacy database that stores session records.
    |
    */

    'table' => env('LEGACY_BRIDGE_TABLE', 'sessions'),

    /*
    |--------------------------------------------------------------------------
    | Session Lifetime
    |--------------------------------------------------------------------------
    |
    | The maximum age of a legacy session in minutes. Sessions older than this
    | will not be bridged. Defaults to matching your Laravel session lifetime.
    |
    */

    'lifetime' => env('LEGACY_BRIDGE_LIFETIME', config('session.lifetime', 120)),

    /*
    |--------------------------------------------------------------------------
    | Payload Format
    |--------------------------------------------------------------------------
    |
    | The format used by your legacy application to store session payloads.
    |
    | Supported: "auto", "php_session", "json", "laravel", "encrypted"
    |
    | Use "auto" to let the package detect the format from a sample payload.
    | Use "encrypted" when the legacy app encrypted sessions with its own
    | APP_KEY — you must also set "app_key" below.
    |command:workbench.trust.manage
    */

    'format' => env('LEGACY_BRIDGE_PAYLOAD_FORMAT', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Legacy App Key (encrypted sessions only)
    |--------------------------------------------------------------------------
    |
    | Required when format is "encrypted". This is the APP_KEY value from
    | your legacy Laravel application, used to decrypt session payloads
    | before parsing.
    |
    */

    'app_key' => env('LEGACY_BRIDGE_APP_KEY'),

    /*
    |--------------------------------------------------------------------------
    | User Resolver
    |--------------------------------------------------------------------------
    |
    | The driver used to extract a user ID from the decoded legacy session
    | payload. Built-in drivers cover common legacy formats. Use "custom"
    | and set "resolver_class" to provide your own implementation.
    |
    | Built-in drivers: "auto", "key", "laravel4", "sentinel", "sentry", "custom"
    |
    */

    'resolver' => [
        'driver' => env('LEGACY_BRIDGE_RESOLVER_DRIVER', 'auto'),

        // For driver "key": the dot-notation path to the user ID in the payload.
        'key' => env('LEGACY_BRIDGE_RESOLVER_KEY', 'user_id'),

        // For driver "custom": the FQCN of your LegacyUserResolver implementation.
        'class' => env('LEGACY_BRIDGE_RESOLVER_CLASS', 'App\Bridge\LegacyUserResolver'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Keys
    |--------------------------------------------------------------------------
    |
    | Additional session keys to carry from the legacy payload into the new
    | Laravel session after identity is resolved. Useful for locale, timezone,
    | cart state, or any other per-user context your legacy app maintains.
    |
    */

    'context' => [
        'carry_keys' => [
            // 'locale',
            // 'timezone',
            // 'cart_id',
        ],

        // Whether to carry flash data from the legacy session.
        'flash' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Invalidation Strategy
    |--------------------------------------------------------------------------
    |
    | Controls what happens to the legacy session after a successful bridge.
    |
    | "after_write"  — delete the legacy session after Laravel writes its own
    |                  (recommended: clean, one-way migration per user)
    | "immediate"    — delete before handing off to the next middleware
    | "never"        — leave the legacy session intact (useful for debugging
    |                  or when the legacy app must remain authoritative)
    |
    */

    'invalidation' => env('LEGACY_BRIDGE_INVALIDATION', 'after_write'),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | When enabled, each successful bridge is logged with the user ID and a
    | truncated session ID. Useful during rollout to monitor migration rate.
    |
    */

    'logging' => [
        'enabled' => env('LEGACY_BRIDGE_LOGGING', true),
        'channel' => env('LEGACY_BRIDGE_LOG_CHANNEL', null), // null = default channel
    ],

];

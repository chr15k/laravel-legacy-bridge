<?php

use Chr15k\LegacyBridge\Integrations\Laravel;

return [

    /*
    |--------------------------------------------------------------------------
    | Legacy Integration
    |--------------------------------------------------------------------------
    |
    | Defines how the bridge interacts with your legacy application.
    |
    | An integration encapsulates all framework-specific logic required to:
    | - Locate and read legacy session records
    | - Decode and interpret session payloads
    | - Resolve the authenticated user from session data
    | - Handle any framework-specific quirks (schema, serialization, timestamps)
    |
    | Built-in integrations are provided for common legacy frameworks such as
    | Laravel and CodeIgniter, but you may also implement your own by
    | creating a class that implements:
    |
    |   \Chr15k\LegacyBridge\Contracts\Integration
    |
    | Example:
    |
    |   'integration' => \Chr15k\LegacyBridge\Integrations\Laravel::class,
    |
    | or a custom implementation:
    |
    |   'integration' => App\Bridge\MyLegacyIntegration::class,
    |
    | This approach allows the package to remain framework-agnostic while
    | supporting a wide range of legacy application architectures.
    |
    */
    'integration' => env('LEGACY_INTEGRATION', Laravel::class),

    /*
    |--------------------------------------------------------------------------
    | Legacy Session Cookie
    |--------------------------------------------------------------------------
    |
    | The name of the cookie your legacy application sets. This is used by
    | the middleware to find an existing session to bridge from.
    |
    */

    'cookie' => env('LEGACY_SESSION_COOKIE', 'PHPSESSID'),

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

    'connection' => env('LEGACY_DB_CONNECTION', 'legacy'),

    /*
    |--------------------------------------------------------------------------
    | Legacy Sessions Table
    |--------------------------------------------------------------------------
    |
    | The table name in the legacy database that stores session records.
    |
    */

    'table' => env('LEGACY_SESSION_TABLE', 'sessions'),

    /*
    |--------------------------------------------------------------------------
    | Session Lifetime
    |--------------------------------------------------------------------------
    |
    | The maximum age of a legacy session in minutes. Sessions older than this
    | will not be bridged. Defaults to matching your Laravel session lifetime.
    |
    */

    'lifetime' => env('LEGACY_SESSION_LIFETIME', config('session.lifetime', 120)),

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
    | APP_KEY — you must also set "legacy_app_key" below.
    |
    | If your legacy app is itself a Laravel application, this is almost
    | always "laravel" — NOT "encrypted". "encrypted" only applies if the
    | legacy app explicitly set SESSION_ENCRYPT=true, which is uncommon.
    |
    | If unsure, set "laravel" first and run legacy-bridge:verify --session-id=
    | to confirm the payload decodes correctly.
    |
    */

    'format' => env('LEGACY_SESSION_FORMAT', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Legacy App Key
    |--------------------------------------------------------------------------
    |
    | The APP_KEY from your legacy Laravel application. Used to decrypt the
    | session cookie (when cookie_encryption is "laravel") and/or the session
    | payload (when format is "encrypted"). Most legacy Laravel apps will
    | need both set together.
    |
    */

    'legacy_app_key' => env('LEGACY_APP_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Legacy Cookie Encryption
    |--------------------------------------------------------------------------
    |
    | Whether the legacy session cookie value itself needs decrypting before
    | it can be used as a lookup key against the sessions table.
    |
    | "none"    — the cookie value is the raw session ID (plain PHP apps,
    |             or a Laravel app that excluded this cookie from
    |             EncryptCookies). This is the default for most legacy apps.
    |
    | "laravel" — the legacy app is itself a Laravel app and the cookie was
    |             encrypted by its EncryptCookies middleware. The cookie
    |             must be decrypted with legacy_app_key below to recover
    |             the raw session ID before it can be looked up.
    |
    | Note: this is independent of "format" above. "format" describes the
    | session *payload* stored in the database; this describes the *cookie*
    | sent by the browser. A legacy Laravel app will typically need both
    | "cookie_encryption" and "format" set, sharing the same legacy_app_key.
    |
    */
    'cookie_encryption' => env('LEGACY_COOKIE_ENCRYPTION', 'none'), // 'none' | 'laravel'

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
        'driver' => env('LEGACY_RESOLVER_DRIVER', 'auto'),

        // For driver "key": the dot-notation path to the user ID in the payload.
        'key' => env('LEGACY_RESOLVER_KEY', 'user_id'),

        // For driver "custom": the FQCN of your LegacyUserResolver implementation.
        'class' => env('LEGACY_RESOLVER_CLASS', 'App\Bridge\LegacyUserResolver'),
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

    'invalidation' => env('LEGACY_SESSION_INVALIDATION', 'after_write'),

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

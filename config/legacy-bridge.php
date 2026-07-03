<?php

use Chr15k\LegacyBridge\Enums\SessionTimeFormat;
use Chr15k\LegacyBridge\Enums\SessionTimeSemantics;

return [

    'cookie' => [
        /*
        |--------------------------------------------------------------------------
        | Legacy Session Cookie
        |--------------------------------------------------------------------------
        |
        | The name of the cookie your legacy application sets. This is used by
        | the middleware to find an existing session to bridge from.
        |
        */

        'name' => env('LEGACY_BRIDGE_COOKIE', 'PHPSESSID'),

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
        'encryption' => env('LEGACY_BRIDGE_COOKIE_ENCRYPTION', 'none'), // 'none' | 'laravel'

    ],

    'database' => [
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
        | The table name and columns in the legacy database that stores session records.
        |
        */

        'table' => env('LEGACY_BRIDGE_SESSION_TABLE', 'sessions'),

        'columns' => [
            'id'      => env('LEGACY_BRIDGE_SESSION_TABLE_COL_ID', 'id'),
            'payload' => env('LEGACY_BRIDGE_SESSION_TABLE_COL_PAYLOAD', 'payload'),
            'time'    => env('LEGACY_BRIDGE_SESSION_TABLE_COL_TIME', 'last_activity'),
        ],

        'time' => [
            'semantics' => SessionTimeSemantics::tryFrom(env('LEGACY_BRIDGE_SESSION_TIME_SEMANTICS'))
                ?? SessionTimeSemantics::Activity,
            'format' => SessionTimeFormat::tryFrom(env('LEGACY_BRIDGE_SESSION_TIME_FORMAT'))
                ?? SessionTimeFormat::Timestamp,
        ],
    ],

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
    |
    | If your legacy app is itself a Laravel application, this is almost
    | always "laravel" — NOT "encrypted". "encrypted" only applies if the
    | legacy app explicitly set SESSION_ENCRYPT=true, which is uncommon.
    |
    | If unsure, set "laravel" first and run legacy-bridge:verify --session-id=
    | to confirm the payload decodes correctly.
    |
    */

    'payload' => [
        'format' => env('LEGACY_BRIDGE_PAYLOAD_FORMAT', 'auto'),
    ],

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

    'app_key' => env('LEGACY_BRIDGE_APP_KEY'),

    /*
    |--------------------------------------------------------------------------
    | User Resolver
    |--------------------------------------------------------------------------
    |
    | The driver used to extract a user ID from the decoded legacy session
    | payload.
    |
    | IMPORTANT: The resolved user ID is used directly to look up a user in
    | your new application's users table via Auth::loginUsingId(). This
    | assumes the user IDs in your legacy session payload match the IDs in
    | your new application's users table.
    |
    | If your migration involved re-seeding users with new IDs, or if legacy
    | and new user IDs differ for any reason, you must handle the mapping in
    | a custom resolver before returning the ID:
    |
    |   public function resolve(LegacyPayload $payload): ?int
    |   {
    |       $legacyId = $payload->resolveId('user_id');
    |
    |       return DB::table('user_id_map')
    |           ->where('legacy_id', $legacyId)
    |           ->value('new_id');
    |   }
    |
    | Built-in drivers: "auto", "key", "custom"
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

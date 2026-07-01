# Laravel Legacy Bridge — User Guide

This guide walks through a complete implementation of `laravel-legacy-bridge` for a real-world
Strangler Fig migration. By the end you will have a working session bridge that authenticates
users from your legacy application into your new Laravel app without forcing them to log in again.

For a fast quickstart, see the [README](README.md). This guide covers every step in depth,
including configuration choices, edge cases, and troubleshooting.

---

## Contents

- [Before you start](#before-you-start)
- [Installation](#installation)
- [Step 1 — Configure the database connection](#step-1--configure-the-database-connection)
- [Step 2 — Create the sessions table](#step-2--create-the-sessions-table)
- [Step 3 — Register the middleware](#step-3--register-the-middleware)
- [Step 4 — Cookie naming](#step-4--cookie-naming)
- [Step 5 — Implement a resolver (optional)](#step-5--implement-a-resolver-optional)
- [Step 6 — Verify the configuration](#step-6--verify-the-configuration)
- [Payload format](#payload-format)
- [Legacy Laravel applications](#legacy-laravel-applications)
- [Carrying additional context](#carrying-additional-context)
- [Invalidation strategies](#invalidation-strategies)
- [Cookie alignment](#cookie-alignment)
- [Shared database](#shared-database)
- [Monitoring the migration](#monitoring-the-migration)
- [Handling user eligibility](#handling-user-eligibility)
- [Removing the bridge](#removing-the-bridge)
- [Configuration reference](#configuration-reference)
- [Troubleshooting](#troubleshooting)

---

## Before you start

You will need:

- A Laravel 12 or 13 application (the new app)
- A legacy PHP application (any framework, or plain PHP — including a legacy Laravel app)
- Access to the legacy application's session database table
- The legacy session cookie name (commonly `PHPSESSID`, or `laravel_session` if the legacy app is also Laravel)

You do **not** need to know your legacy payload structure in advance — the bundled `auto`
resolver covers most common cases, and the `legacy-bridge:verify` command will help you confirm
or refine it once the package is installed.

---

## Installation

```bash
composer require chr15k/laravel-legacy-bridge
php artisan legacy-bridge:install
```

The install command will:

- Publish `config/legacy-bridge.php`
- Print the remaining setup steps

> [!NOTE]
> Excluding the legacy cookie from Laravel's cookie encryption is handled automatically by the
> package's service provider. You do not need to configure `EncryptCookies` yourself.

---

## Step 1 — Configure the database connection

### Separate databases (most common)

Add a `legacy` connection to `config/database.php`:

```php
'connections' => [
    // your existing connections...

    'legacy' => [
        'driver'   => 'mysql',
        'host'     => env('LEGACY_DB_HOST', '127.0.0.1'),
        'port'     => env('LEGACY_DB_PORT', '3306'),
        'database' => env('LEGACY_DB_DATABASE'),
        'username' => env('LEGACY_DB_USERNAME'),
        'password' => env('LEGACY_DB_PASSWORD'),
        'charset'  => 'utf8mb4',
        'prefix'   => '',
    ],
],
```

Then add the credentials to `.env`:

```dotenv
LEGACY_BRIDGE_DB_CONNECTION=legacy
LEGACY_DB_HOST=127.0.0.1
LEGACY_DB_PORT=3306
LEGACY_DB_DATABASE=your_legacy_database
LEGACY_DB_USERNAME=your_user
LEGACY_DB_PASSWORD=your_password
LEGACY_BRIDGE_COOKIE=PHPSESSID
```

### Shared database

If both applications use the same database, no new connection is needed. Point the bridge at
your existing connection and specify the legacy sessions table name if it differs from `sessions`:

```dotenv
LEGACY_BRIDGE_DB_CONNECTION=mysql
LEGACY_BRIDGE_TABLE=legacy_sessions
```

---

## Step 2 — Create the sessions table

Your new Laravel application needs its own sessions table, separate from the legacy one. If
you have not already created it:

```bash
php artisan make:session-table
php artisan migrate
```

Then set the session driver in `.env`:

```dotenv
SESSION_DRIVER=database
```

---

## Step 3 — Register the middleware

The bridge middleware must run on every web request, after Laravel's session has been started.

In `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [
        \Chr15k\LegacyBridge\Http\Middleware\LegacySessionBridge::class,
    ]);
})
```

> [!NOTE]
> Place the middleware **after** `StartSession` in the stack — appending it to the `web` group
> as shown above already guarantees this ordering. Cookie encryption exclusion for the legacy
> cookie is registered automatically by the package; no extra `encryptCookies()` configuration
> is required.

---

## Step 4 — Cookie naming

If your legacy application is also a Laravel app and never customised its session cookie name,
it is almost certainly using Laravel's default: `laravel_session`. Your new application's
session cookie **must** use a different name to avoid a collision — the browser can only hold
one cookie per name per domain, so if both apps used the same name, whichever response set it
last would silently overwrite the other.

```dotenv
# New application — choose a distinct name
SESSION_COOKIE=myapp_session

# Legacy application cookie name (unchanged)
LEGACY_BRIDGE_COOKIE=laravel_session
```

If your legacy app is plain PHP, its cookie is typically `PHPSESSID`, which does not conflict
with Laravel's `laravel_session` default — no change needed.

The `legacy-bridge:verify` command will warn you automatically if both cookies share the same name.

---

## Step 5 — Implement a resolver (optional)

The resolver tells the bridge where the authenticated user ID lives in your legacy session
payload. **Most consumers do not need to implement one** — the bundled `auto` driver already
tries a sequence of known structures (plain PHP, nested arrays, Cartalyst Sentinel and Sentry,
older Laravel auth session keys) and resolves the user ID automatically in most cases.

Only implement a custom resolver if:

- `legacy-bridge:verify --session-id=...` shows the `auto` driver cannot resolve a user ID, or
- you want to lock down resolution to an explicit path before going to production (recommended)

### Using the built-in `key` driver

If you know the exact dot-notation path to the user ID, you can skip writing any code:

```dotenv
LEGACY_BRIDGE_RESOLVER_DRIVER=key
LEGACY_BRIDGE_RESOLVER_KEY=user_id

# Or for a nested structure:
LEGACY_BRIDGE_RESOLVER_KEY=auth.user.id
```

### Writing a custom resolver

For anything more involved, implement the `LegacyUserResolver` contract:

```php
namespace App\Bridge;

use Chr15k\LegacyBridge\Payload\LegacyPayload;
use Chr15k\LegacyBridge\Contracts\LegacyUserResolver as Contract;

class LegacyUserResolver implements Contract
{
    public function resolve(LegacyPayload $payload): ?int
    {
        return $payload->resolveId('user_id');
    }
}
```

Then point the config at it:

```php
// config/legacy-bridge.php
'resolver' => [
    'driver' => 'custom',
    'class'  => \App\Bridge\LegacyUserResolver::class,
],
```

### Working with the LegacyPayload API

The `LegacyPayload` object gives you safe, expressive access to the decoded session data.

**Dot-notation access for nested structures:**

```php
// Legacy payload: ['auth' => ['user' => ['id' => 42]]]
return $payload->resolveId('auth.user.id');
```

**Checking before accessing:**

```php
if ($payload->has('admin_user_id')) {
    return $payload->resolveId('admin_user_id');
}

return $payload->resolveId('user_id');
```

**Handling serialized objects:**

`resolveId()` handles the case where the value at a path is a serialized object with an `id`
property — you don't need to unwrap it manually:

```php
// Legacy payload stores a User object at 'auth_user'
// resolveId() reads ->id automatically
return $payload->resolveId('auth_user');
```

**Common legacy structures:**

| Legacy app | Payload structure | Resolver |
|---|---|---|
| Plain PHP | `['user_id' => 42]` | `$payload->resolveId('user_id')` |
| Nested array | `['auth' => ['id' => 42]]` | `$payload->resolveId('auth.id')` |
| Serialized object | `['user' => User{id: 42}]` | `$payload->resolveId('user')` |
| Cartalyst Sentinel | `['cartalyst_sentinel' => ...]` | `$payload->resolveId('cartalyst_sentinel.id')` |
| Multiple guards | `['admin_id' => null, 'user_id' => 42]` | Check `has()` for each |

---

## Step 6 — Verify the configuration

Before routing any real traffic through the bridge, run:

```bash
php artisan legacy-bridge:verify
```

This checks:

- The config file is present and readable
- The legacy database connection is reachable
- The sessions table exists and has rows
- The resolver is correctly configured
- The cookie names do not collide

### Testing a real session ID

Find a session ID from your legacy sessions table and pass it to the verify command:

```bash
php artisan legacy-bridge:verify --session-id=abc123def456
```

The command shows exactly what the bridge would do with that session — format detected, payload
keys found, user ID resolved, user existence confirmed — without actually authenticating anyone
or modifying any data.

Example output:

```
  laravel-legacy-bridge — configuration check

┌─────────────────────────────────────────────────────┐
│ Config                                              │
│ file         config/legacy-bridge.php              │
│ cookie       PHPSESSID                             │
│ connection   legacy                                │
│ table        sessions                              │
│ format       auto                                  │
│ invalidation after_write                           │
└─────────────────────────────────────────────────────┘

  ✓ Connected to legacy.sessions (1,842 sessions)
  ✓ Resolver ready: auto
  ✓ Cookie alignment OK: legacy=PHPSESSID  laravel=laravel_session

  Testing session ID: abc123def456…

┌─────────────────────────────────────────────────────┐
│ Session resolved                                    │
│ session_id   abc123def456…                         │
│ format       php_session                           │
│ age          4m (lifetime: 120m)                   │
│ keys         user_id, locale, _token               │
│ user_id      42                                    │
│ user_found   yes                                   │
└─────────────────────────────────────────────────────┘

  All checks passed.
```

---

## Payload format

The `format` setting tells the bridge how to decode the legacy session **payload** (the contents
of the `payload` column). This is separate from cookie encryption, which is covered in
[Legacy Laravel applications](#legacy-laravel-applications).

### Supported formats

| Format | Description |
|---|---|
| `auto` | Detects the format automatically (recommended starting point) |
| `php_session` | Native PHP session encoding (`key\|serialized;`) |
| `json` | JSON-encoded payload, raw or base64-wrapped |
| `laravel` | Laravel's `base64(serialize($array))` format — the default for most Laravel apps |
| `encrypted` | Laravel session payload additionally encrypted via `SESSION_ENCRYPT=true` — requires `LEGACY_BRIDGE_APP_KEY` |

If you are unsure which applies, leave `format` as `auto` and run the verify command against a
real session ID — it will show you the detected format and decoded keys.

Once confirmed, set it explicitly for production:

```dotenv
LEGACY_BRIDGE_PAYLOAD_FORMAT=php_session   # Native PHP session encoding
LEGACY_BRIDGE_PAYLOAD_FORMAT=laravel       # Laravel base64(serialize()) format
LEGACY_BRIDGE_PAYLOAD_FORMAT=json          # JSON-encoded payload
LEGACY_BRIDGE_PAYLOAD_FORMAT=encrypted     # Laravel SESSION_ENCRYPT=true (requires LEGACY_BRIDGE_APP_KEY)
```

### LegacyPayload API reference

Once decoded, the payload is wrapped in a `LegacyPayload` object with a consistent API
regardless of the original format:

```php
$payload->get('user_id');              // top-level key
$payload->get('auth.user.id');         // nested key, dot notation
$payload->get('missing', 'default');   // with fallback

$payload->resolveId('user_id');        // safe ID — handles scalar, array['id'], object->id
$payload->has('cart_id');              // existence check
$payload->all();                       // all decoded keys as array
$payload->only(['locale', 'timezone']); // subset of keys
$payload->format();                    // detected format string
```

---

## Legacy Laravel applications

If your legacy application is itself a Laravel app, there are two **independent** encryption
layers to be aware of: the cookie value, and the session payload. Most legacy Laravel apps only
have the cookie encrypted (Laravel's unavoidable default) while leaving the payload itself
unencrypted.

### Cookie encryption

Laravel's `EncryptCookies` middleware encrypts all cookie values by default, including the
session cookie. This means the raw cookie value sent by the browser is not the session ID — it
is an encrypted blob that must be decrypted with the legacy app's `APP_KEY` before it can be
used to look up a row in the sessions table.

If your legacy app never excluded its session cookie from encryption (the default for most
Laravel apps), set:

```dotenv
LEGACY_BRIDGE_COOKIE_ENCRYPTED=laravel
LEGACY_BRIDGE_APP_KEY=base64:your_app_key_here
```

`LEGACY_BRIDGE_APP_KEY` must be the `APP_KEY` value from the **legacy** application's own `.env` — not
your new application's key.

If the legacy app explicitly excluded its session cookie from encryption, set:

```dotenv
LEGACY_BRIDGE_COOKIE_ENCRYPTED=none
```

### Payload encryption

Separately, the session **payload** stored in the database may or may not be encrypted,
depending on whether the legacy app set `SESSION_ENCRYPT=true`. This is a less common setting —
most Laravel apps leave it at its default of `false`.

```dotenv
# Most legacy Laravel apps — payload is plain base64(serialize())
LEGACY_BRIDGE_PAYLOAD_FORMAT=laravel

# Only if the legacy app explicitly set SESSION_ENCRYPT=true
LEGACY_BRIDGE_PAYLOAD_FORMAT=encrypted
```

Both `cookie_encryption: laravel` and `format: encrypted` use the same `LEGACY_BRIDGE_APP_KEY` — set
it once and it is used wherever decryption is needed.

### Typical configuration for a legacy Laravel app

The most common combination, covering a standard Laravel app with default settings:

```dotenv
LEGACY_BRIDGE_COOKIE_ENCRYPTED=laravel
LEGACY_BRIDGE_PAYLOAD_FORMAT=laravel
LEGACY_BRIDGE_APP_KEY=base64:your_app_key_here
```

If you are unsure which combination applies, run the verify command against a real session ID —
it will show you exactly where decoding succeeds or fails:

```bash
php artisan legacy-bridge:verify --session-id=YOUR_SESSION_ID
```

---

## Carrying additional context

Beyond the authenticated user, you can carry other session values from the legacy payload
into the new Laravel session. This is useful for locale, timezone, cart state, or any
per-user context the legacy app maintained.

```php
// config/legacy-bridge.php
'context' => [
    'carry_keys' => ['locale', 'timezone', 'cart_id'],
    'flash'      => true, // also carry legacy flash data
],
```

Values listed in `carry_keys` are read from the legacy payload and written to Laravel's session
on the first bridged request. They are then available via `session('locale')` etc. as normal.

For full control, implement `LegacyContextResolver` and bind it in a service provider:

```php
use Chr15k\LegacyBridge\Payload\LegacyPayload;
use Chr15k\LegacyBridge\Contracts\LegacyContextResolver;

$this->app->singleton(LegacyContextResolver::class, function () {
    return new class implements LegacyContextResolver {
        public function resolve(?int $userId, LegacyPayload $payload): array
        {
            return [
                'locale'   => $payload->get('locale', 'en'),
                'timezone' => $payload->get('timezone', 'UTC'),
            ];
        }
    };
});
```

---

## Invalidation strategies

| Strategy | Behaviour | When to use |
|---|---|---|
| `after_write` | Delete legacy session after Laravel writes its own | Production (default) |
| `immediate` | Delete legacy session before next middleware | When legacy app must not see session again |
| `never` | Leave legacy session intact | Debugging only |

```dotenv
LEGACY_BRIDGE_INVALIDATION=after_write
```

`after_write` is the recommended default. It ensures each legacy session can only be bridged
once — after the user crosses over, the legacy session is gone and they hold a standard Laravel
session.

Avoid `never` in production. It leaves session rows in the legacy database indefinitely.

---

## Cookie alignment

Both cookies must be able to reach the browser simultaneously during the transition window.
Keep the names distinct — see [Step 4 — Cookie naming](#step-4--cookie-naming) above.

If both apps sit on different subdomains, set the session domain so both cookies are sent on
cross-subdomain requests:

```dotenv
SESSION_DOMAIN=.yourdomain.com
```

---

## Shared database

If both applications use the same database, set `LEGACY_BRIDGE_DB_CONNECTION` to your default
connection and point `LEGACY_BRIDGE_TABLE` at the legacy sessions table (if it differs from
`sessions`):

```dotenv
LEGACY_BRIDGE_DB_CONNECTION=mysql
LEGACY_BRIDGE_TABLE=legacy_sessions
```

No second DB connection is needed.

---

## Monitoring the migration

Enable logging to track bridge activity:

```dotenv
LEGACY_BRIDGE_LOGGING=true
LEGACY_BRIDGE_LOG_CHANNEL=stack  # or any channel from config/logging.php
```

Each successful bridge logs:

```
legacy-bridge: session bridged {"user_id":42,"session_id":"abc123de…","format":"php_session"}
```

Watch this log channel during rollout. As users cross over, the rate of bridge events will
naturally decline. When `legacy-bridge: session bridged` stops appearing entirely, every active
user has been migrated and the bridge is no longer doing any work.

---

## Handling user eligibility

The bridge calls `Auth::loginUsingId()` without checking whether the user is active, verified,
or permitted to access the new application. Apply any such checks via Laravel's authentication
events:

```php
// app/Listeners/EnsureUserIsActive.php
use Illuminate\Auth\Events\Login;

class EnsureUserIsActive
{
    public function handle(Login $event): void
    {
        if (! $event->user->is_active) {
            Auth::logout();
            abort(403, 'Account is inactive.');
        }
    }
}
```

Register it in `EventServiceProvider`:

```php
protected $listen = [
    \Illuminate\Auth\Events\Login::class => [
        \App\Listeners\EnsureUserIsActive::class,
    ],
];
```

---

## Removing the bridge

Once the legacy application is decommissioned or all active sessions have been migrated:

1. Confirm `legacy-bridge: session bridged` has stopped appearing in your logs
2. Remove the middleware from `bootstrap/app.php`
3. Remove the legacy database connection from `config/database.php`
4. Uninstall the package:

```bash
composer remove chr15k/laravel-legacy-bridge
```

The bridge is designed to leave nothing behind. Once removed, your application has no dependency
on the legacy sessions table or any bridge infrastructure.

---

## Configuration reference

```php
// config/legacy-bridge.php

return [
    'cookie'            => env('LEGACY_BRIDGE_COOKIE', 'PHPSESSID'),
    'connection'        => env('LEGACY_BRIDGE_DB_CONNECTION', 'legacy'),
    'table'             => env('LEGACY_BRIDGE_TABLE', 'sessions'),
    'lifetime'          => env('LEGACY_BRIDGE_LIFETIME', 120),
    'format'            => env('LEGACY_BRIDGE_PAYLOAD_FORMAT', 'auto'),
    'cookie_encryption' => env('LEGACY_BRIDGE_COOKIE_ENCRYPTED', 'none'), // 'none' | 'laravel'
    'app_key'    => env('LEGACY_BRIDGE_APP_KEY'),

    'resolver' => [
        'driver' => env('LEGACY_BRIDGE_RESOLVER_DRIVER', 'auto'), // 'auto' | 'key' | 'custom'
        'key'    => env('LEGACY_BRIDGE_RESOLVER_KEY', 'user_id'),
        'class'  => env('LEGACY_BRIDGE_RESOLVER_CLASS'),
    ],

    'context' => [
        'carry_keys' => [],
        'flash'      => false,
    ],

    'invalidation' => env('LEGACY_BRIDGE_INVALIDATION', 'after_write'), // 'after_write' | 'immediate' | 'never'

    'logging' => [
        'enabled' => env('LEGACY_BRIDGE_LOGGING', true),
        'channel' => env('LEGACY_BRIDGE_LOG_CHANNEL', null),
    ],
];
```

| Key | Default | Description |
|---|---|---|
| `cookie` | `PHPSESSID` | Name of the legacy session cookie |
| `connection` | `legacy` | DB connection name for the legacy database |
| `table` | `sessions` | Table name for legacy sessions |
| `lifetime` | `120` | Minutes a legacy session remains valid |
| `format` | `auto` | Legacy session payload format |
| `cookie_encryption` | `none` | Whether the cookie value itself needs decrypting (`none` or `laravel`) |
| `app_key` | — | The legacy app's `APP_KEY`, used for cookie and/or payload decryption |
| `resolver.driver` | `auto` | `auto`, `key`, or `custom` |
| `resolver.key` | `user_id` | Dot-notation path used by the `key` driver |
| `resolver.class` | — | FQCN of a custom resolver, used by the `custom` driver |
| `context.carry_keys` | `[]` | Additional session keys to carry across the boundary |
| `context.flash` | `false` | Whether to carry legacy flash data |
| `invalidation` | `after_write` | When to delete the legacy session row |
| `logging.enabled` | `true` | Whether bridge activity is logged |
| `logging.channel` | — | Log channel (defaults to the app's default channel) |

---

## Troubleshooting

### Sessions are not being bridged

1. Confirm the middleware is registered and in the `web` group
2. Run `legacy-bridge:verify --session-id=YOUR_ID` to test a real session
3. Check the log channel for `legacy-bridge:` entries
4. Confirm `SESSION_DRIVER=database` and the new sessions table exists

### Users are being logged out after bridging

The legacy session is being deleted (`after_write`) before Laravel has written its own. This
can happen if the Laravel session write fails — check for session configuration errors,
a missing sessions table, or an incorrect `SESSION_CONNECTION`.

### Cookie is arriving encrypted or garbled

If your legacy app is also Laravel, its session cookie is encrypted by default. Set
`LEGACY_BRIDGE_COOKIE_ENCRYPTED=laravel` and `LEGACY_BRIDGE_APP_KEY` as described in
[Legacy Laravel applications](#legacy-laravel-applications).

### Resolver returns null

Run `legacy-bridge:verify --session-id=YOUR_ID` and check the `keys` field in the output.
This shows every key present in the decoded payload. Set `resolver.key` to the correct path,
or implement a custom resolver.

### Format detection fails / payload is invalid

Set `LEGACY_BRIDGE_PAYLOAD_FORMAT` explicitly rather than using `auto`. If your legacy app is also
Laravel, check whether the actual issue is cookie encryption rather than payload format — the
two are configured independently. See [Legacy Laravel applications](#legacy-laravel-applications).

### Both cookies share the same name

If your legacy app is also Laravel and never customised `SESSION_COOKIE`, it defaults to
`laravel_session`. Set a distinct `SESSION_COOKIE` on the new application. See
[Step 4 — Cookie naming](#step-4--cookie-naming).
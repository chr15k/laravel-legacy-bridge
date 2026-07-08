# Larvel Legacy Bridge — User Guide

This guide walks through a complete implementation of `laravel-legacy-bridge` for a real-world migration.
By the end you will have a working session bridge that authenticates
users from your legacy application into your new Laravel app without forcing them to log in again.

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
- [Framework presets](#framework-presets)
- [Carrying additional context](#carrying-additional-context)
- [Invalidation strategies](#invalidation-strategies)
- [Monitoring and events](#monitoring-and-events)
- [Handling user eligibility](#handling-user-eligibility)
- [Removing the bridge](#removing-the-bridge)
- [Configuration reference](#configuration-reference)
- [Troubleshooting](#troubleshooting)
- [Known Limitations](#known-limitations)

---

## Before you start

You will need:

- A Laravel 13 application (the new app)
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

The install command walks you through setup interactively:

- Asks whether both apps share a database
- Collects legacy DB credentials and writes them to `.env`
- Asks which legacy framework you're migrating from and pre-fills format and resolver settings
- Asks whether you need a custom resolver, and publishes a stub if so
- Publishes `config/legacy-bridge.php`
- Prints the middleware registration snippet

> [!NOTE]
> Excluding the legacy cookie from Laravel's `EncryptCookies` middleware is handled
> automatically by the service provider. No `encryptCookies()` configuration is needed.

---

## Step 1 — Configure the database connection

### Separate databases (most common)

Add a `legacy` connection to `config/database.php`:

```php
'connections' => [
    'legacy' => [
        'driver'   => 'mysql',
        'host'     => env('LEGACY_BRIDGE_DB_HOST', '127.0.0.1'),
        'port'     => env('LEGACY_BRIDGE_DB_PORT', '3306'),
        'database' => env('LEGACY_BRIDGE_DB_DATABASE'),
        'username' => env('LEGACY_BRIDGE_DB_USERNAME'),
        'password' => env('LEGACY_BRIDGE_DB_PASSWORD'),
        'charset'  => 'utf8mb4',
        'prefix'   => '',
    ],
],
```

Add credentials to `.env`:

```dotenv
LEGACY_BRIDGE_DB_CONNECTION=legacy
LEGACY_BRIDGE_DB_HOST=127.0.0.1
LEGACY_BRIDGE_DB_PORT=3306
LEGACY_BRIDGE_DB_DATABASE=your_legacy_database
LEGACY_BRIDGE_DB_USERNAME=your_user
LEGACY_BRIDGE_DB_PASSWORD=your_password
LEGACY_BRIDGE_COOKIE=PHPSESSID
```

### Shared database

If both applications use the same database, no new connection is needed:

```dotenv
LEGACY_BRIDGE_DB_CONNECTION=mysql
LEGACY_BRIDGE_SESSION_TABLE=legacy_sessions
```

---

## Step 2 — Create the sessions table

Your new Laravel application needs its own sessions table, separate from the legacy one:

```bash
php artisan make:session-table
php artisan migrate
```

```dotenv
SESSION_DRIVER=database
```

---

## Step 3 — Register the middleware

In `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [
        \Chr15k\LegacyBridge\Http\Middleware\LegacySessionBridge::class,
    ]);
})
```

> [!NOTE]
> Appending to the `web` group guarantees the bridge runs after `StartSession`. Cookie
> encryption exclusion is registered automatically — no additional configuration required.

---

## Step 4 — Cookie naming

If your legacy app is also Laravel and never customised its session cookie name, it uses
Laravel's default: `laravel_session`. Your new application must use a different name to
avoid a collision.

```dotenv
# New application
SESSION_COOKIE=myapp_session

# Legacy application cookie (unchanged)
LEGACY_BRIDGE_COOKIE=laravel_session
```

If your legacy app is plain PHP, its cookie is typically `PHPSESSID` — no conflict with
Laravel's `laravel_session` default, no change needed.

The `legacy-bridge:verify` command warns you automatically if both cookies share the same name.

---

## Step 5 — Implement a resolver (optional)

The resolver tells the bridge where the authenticated user ID lives in your legacy session
payload. **Most consumers do not need to implement one** — the `auto` driver already tries
a sequence of known structures and resolves the user ID in most cases.

Only implement a custom resolver if:

- `legacy-bridge:verify --session-id=...` shows the `auto` driver cannot resolve a user ID, or
- you want to lock down resolution to an explicit path before going to production (recommended)

### Using the built-in `key` driver

```dotenv
LEGACY_BRIDGE_RESOLVER_DRIVER=key
LEGACY_BRIDGE_RESOLVER_KEY=user_id

# Or for a nested structure:
LEGACY_BRIDGE_RESOLVER_KEY=auth.user.id
```

### Writing a custom resolver

```php
namespace App\Bridge;

use Chr15k\LegacyBridge\Payload\LegacyPayload;
use Chr15k\LegacyBridge\Contracts\LegacyUserResolver as Contract;

class LegacyUserResolver implements Contract
{
    public function resolve(LegacyPayload $payload): int|string|null
    {
        return $payload->resolveId('user_id');
    }
}
```

Update config in `.env`:

```
LEGACY_BRIDGE_RESOLVER_DRIVER=custom
LEGACY_BRIDGE_RESOLVER_CLASS="App\\Bridge\\LegacyUserResolver"
```

### LegacyPayload API

```php
$payload->get('user_id');              // top-level key
$payload->get('auth.user.id');         // nested, dot notation
$payload->get('missing', 'default');   // with fallback

$payload->resolveId('user_id');        // safe ID resolution — handles scalar, array['id'], object->id
$payload->has('cart_id');              // existence check (false if null)
$payload->all();                       // all decoded keys as array
$payload->only(['locale', 'timezone']); // subset
$payload->isEmpty();                   // true if no data
```

> [!WARNING]
> **User ID mapping**
> The resolved user ID is passed directly to `Auth::loginUsingId()` and looked up against
> your new application's users table. The bridge assumes legacy user IDs and new user IDs
> are the same value. If your migration re-seeded users with new IDs, handle the mapping
> in a custom resolver:
>
> ```php
> public function resolve(LegacyPayload $payload): int|string|null
> {
>     $legacyId = $payload->resolveId('user_id');
>
>     return DB::table('user_id_map')
>         ->where('legacy_id', $legacyId)
>         ->value('new_id');
> }
> ```

**Common legacy structures:**

| Legacy app | Payload structure | Resolver |
|---|---|---|
| Plain PHP | `['user_id' => 42]` | `$payload->resolveId('user_id')` |
| Nested array | `['auth' => ['id' => 42]]` | `$payload->resolveId('auth.id')` |
| Serialized object | `['user' => User{id: 42}]` | `$payload->resolveId('user')` |
| Cartalyst Sentinel | `['cartalyst_sentinel' => [...]]` | `$payload->resolveId('cartalyst_sentinel.id')` |
| Multiple guards | `['admin_id' => null, 'user_id' => 42]` | Check `has()` for each |

---

## Step 6 — Verify the configuration

```bash
php artisan legacy-bridge:verify
```

Checks:

- Config is present and readable
- Legacy database connection is reachable
- Sessions table exists and has rows
- Resolver is correctly configured
- Cookie names do not collide

### Testing a real session ID

```bash
php artisan legacy-bridge:verify --session-id=abc123def456
```

Shows exactly what the bridge would do with that session — format detected, payload keys found,
user ID resolved, user existence confirmed — without authenticating anyone or modifying data.

Example output:

```
  laravel-legacy-bridge — configuration check

┌──────────────────────────────────────────────────────┐
│ Config                                               │
│ file         config/legacy-bridge.php               │
│ cookie       PHPSESSID                              │
│ connection   legacy                                 │
│ table        sessions                               │
│ format       auto                                   │
│ invalidation after_write                            │
└──────────────────────────────────────────────────────┘

  ✓ Connected to legacy.sessions (1,842 sessions)
  ✓ Resolver ready: auto
  ✓ Cookie alignment OK: legacy=PHPSESSID  laravel=laravel_session

  Testing session ID: abc123def456…

┌──────────────────────────────────────────────────────┐
│ Session resolved                                     │
│ session_id   abc123def456…                          │
│ format       php_session                            │
│ age          4m (lifetime: 120m)                    │
│ keys         user_id, locale, _token                │
│ user_id      42                                     │
│ user_found   yes                                    │
└──────────────────────────────────────────────────────┘

  All checks passed.
```

---

## Payload format

The `format` setting describes how the **payload column** in the sessions table is encoded.
This is separate from cookie encryption.

| Format | Description |
|---|---|
| `auto` | Detects automatically (recommended starting point) |
| `php_session` | Native PHP session encoding (`key\|serialized;`) |
| `json` | JSON-encoded, raw or base64-wrapped |
| `laravel` | Laravel's `base64(serialize($array))` — the default for most Laravel apps |
| `encrypted` | Laravel `SESSION_ENCRYPT=true` — requires `LEGACY_BRIDGE_APP_KEY` |

```dotenv
LEGACY_BRIDGE_PAYLOAD_FORMAT=laravel
```

### Column mapping

If your legacy sessions table uses different column names, configure them:

```dotenv
LEGACY_BRIDGE_SESSION_TABLE_COL_ID=id
LEGACY_BRIDGE_SESSION_TABLE_COL_PAYLOAD=payload
LEGACY_BRIDGE_SESSION_TABLE_COL_TIME=last_activity
```

### Time column semantics

The `time` column can represent either the last activity time or a future expiry time:

```dotenv
# last_activity: session is valid if time > now() - lifetime (default)
LEGACY_BRIDGE_SESSION_TIME_SEMANTICS=activity

# expires: session is valid if time > now()
LEGACY_BRIDGE_SESSION_TIME_SEMANTICS=expires
```

If the time column stores a datetime string rather than a Unix timestamp:

```dotenv
LEGACY_BRIDGE_SESSION_TIME_FORMAT=datetime
```

---

## Legacy Laravel applications

If your legacy application is itself a Laravel app, there are two independent encryption layers.

### Cookie encryption

Laravel's `EncryptCookies` middleware encrypts cookie values by default. This means the raw
cookie value sent by the browser is not the session ID — it must be decrypted using the legacy
app's `APP_KEY` before lookup.

```dotenv
LEGACY_BRIDGE_COOKIE_ENCRYPTION=laravel
LEGACY_BRIDGE_APP_KEY=base64:your_legacy_app_key_here
```

`LEGACY_BRIDGE_APP_KEY` must be the `APP_KEY` from the **legacy** application's `.env`.

If the legacy app excluded its session cookie from encryption:

```dotenv
LEGACY_BRIDGE_COOKIE_ENCRYPTION=none
```

### Payload encryption

The session payload is only additionally encrypted if the legacy app set `SESSION_ENCRYPT=true`
— uncommon, and separate from cookie encryption.

```dotenv
# Most legacy Laravel apps
LEGACY_BRIDGE_PAYLOAD_FORMAT=laravel

# Only if SESSION_ENCRYPT=true was set on the legacy app
LEGACY_BRIDGE_PAYLOAD_FORMAT=encrypted
```

### Typical configuration for a legacy Laravel app

```dotenv
LEGACY_BRIDGE_COOKIE=laravel_session
LEGACY_BRIDGE_COOKIE_ENCRYPTION=laravel
LEGACY_BRIDGE_PAYLOAD_FORMAT=laravel
LEGACY_BRIDGE_APP_KEY=base64:your_legacy_app_key_here

# New app must use a different cookie name
SESSION_COOKIE=myapp_session
```

---

## Framework presets

The install command pre-fills these settings automatically when you select a framework.
If you're configuring manually, use these as a reference.

### CodeIgniter 3

CI3 stores sessions in `ci_sessions` with a `data` column:

```dotenv
LEGACY_BRIDGE_COOKIE=ci_session
LEGACY_BRIDGE_SESSION_TABLE=ci_sessions
LEGACY_BRIDGE_PAYLOAD_FORMAT=php_session
LEGACY_BRIDGE_SESSION_TABLE_COL_PAYLOAD=data
LEGACY_BRIDGE_SESSION_TABLE_COL_TIME=timestamp
LEGACY_BRIDGE_SESSION_TIME_FORMAT=datetime
```

### CodeIgniter 4

CI4 uses PHP's native session handler with a `ci_sessions` table:

```dotenv
LEGACY_BRIDGE_COOKIE=ci_session
LEGACY_BRIDGE_SESSION_TABLE=ci_sessions
LEGACY_BRIDGE_PAYLOAD_FORMAT=php_session
LEGACY_BRIDGE_RESOLVER_DRIVER=key
LEGACY_BRIDGE_RESOLVER_KEY=user.id
LEGACY_BRIDGE_SESSION_TABLE_COL_PAYLOAD=data
LEGACY_BRIDGE_SESSION_TABLE_COL_TIME=timestamp
LEGACY_BRIDGE_SESSION_TIME_FORMAT=datetime
```

### Symfony

Symfony uses PHP native sessions stored in `PHPSESSID`. The user is stored as a serialized
security token, so a custom resolver is required:

```dotenv
LEGACY_BRIDGE_COOKIE=PHPSESSID
LEGACY_BRIDGE_PAYLOAD_FORMAT=php_session
LEGACY_BRIDGE_RESOLVER_DRIVER=custom
```

```php
public function resolve(LegacyPayload $payload): int|string|null
{
    $token = $payload->get('_sf2_attributes._security_main');

    if (! $token) {
        return null;
    }

    $unserialized = @unserialize($token);

    return $unserialized?->getUser()?->getId() ?? null;
}
```

---

## Carrying additional context

Beyond the authenticated user, you can carry other session values into Laravel's session.

```php
// config/legacy-bridge.php
'context' => [
    'carry_keys' => ['locale', 'timezone', 'cart_id'],
    'flash'      => true, // carry legacy flash data
],
```

For full control, implement `LegacyContextResolver` and bind it in a service provider:

```php
use Chr15k\LegacyBridge\Payload\LegacyPayload;
use Chr15k\LegacyBridge\Contracts\LegacyContextResolver;

$this->app->singleton(LegacyContextResolver::class, function () {
    return new class implements LegacyContextResolver {
        public function resolve(int|string|null $userId, LegacyPayload $payload): array
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
| `immediate` | Delete legacy session before passing to next middleware | When legacy app must not see session again |
| `never` | Leave legacy session intact | Debugging only |

```dotenv
LEGACY_BRIDGE_INVALIDATION_STRATEGY=after_write
```

---

## Monitoring and events

The bridge dispatches events instead of writing to logs. Listen to them in your application
to implement monitoring, alerting, or custom logging.

### LegacySessionBridged

Dispatched on every successful bridge. Use this to track migration rate:

```php
use Chr15k\LegacyBridge\Events\LegacySessionBridged;

class TrackMigrationProgress
{
    public function handle(LegacySessionBridged $event): void
    {
        // $event->userId, $event->sessionId, $event->payload
        Metric::increment('legacy_bridge.success');
    }
}
```

### LegacySessionBridgeFailed

Dispatched on every known failure. The `BridgeFailureReason` enum tells you exactly what went wrong:

```php
use Chr15k\LegacyBridge\Events\LegacySessionBridgeFailed;
use Chr15k\LegacyBridge\Enums\BridgeFailureReason;

class HandleBridgeFailure
{
    public function handle(LegacySessionBridgeFailed $event): void
    {
        // $event->reason  — BridgeFailureReason enum
        // $event->context — BridgeContext DTO

        if ($event->reason === BridgeFailureReason::AuthenticationFailed) {
            // User ID was found but doesn't exist in the new app
            // Likely an ID mismatch — check user_id_map
        }

        Metric::increment('legacy_bridge.failure.'.$event->reason->value);
    }
}
```

### LegacySessionBridgeError

Dispatched on unexpected exceptions (DB connection failure, decoder error, etc.):

```php
use Chr15k\LegacyBridge\Events\LegacySessionBridgeError;

class HandleBridgeError
{
    public function handle(LegacySessionBridgeError $event): void
    {
        // $event->exception — the Throwable
        // Alert on this — it's unexpected
        Notification::route('slack', config('services.slack.webhook'))
            ->notify(new BridgeErrorAlert($event->exception));
    }
}
```

Register listeners in your `EventServiceProvider` or `AppServiceProvider`:

```php
use Illuminate\Support\Facades\Event;

Event::listen(LegacySessionBridged::class, TrackMigrationProgress::class);
Event::listen(LegacySessionBridgeFailed::class, HandleBridgeFailure::class);
Event::listen(LegacySessionBridgeError::class, HandleBridgeError::class);
```

### Knowing when migration is complete

Watch `LegacySessionBridged` event volume over time. As users cross over, the rate naturally
declines. When it reaches zero, every active user has been migrated.

---

## Handling user eligibility

The bridge calls `Auth::loginUsingId()` without checking whether the user is active, verified,
or permitted to access the new application. Apply checks via Laravel's authentication events:

```php
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

---

## Removing the bridge

Once migration is complete:

1. Confirm `LegacySessionBridged` events have stopped firing
2. Remove the middleware from `bootstrap/app.php`
3. Remove the legacy database connection from `config/database.php`
4. Uninstall:

```bash
composer remove chr15k/laravel-legacy-bridge
```

---

## Configuration reference

| Key | Default | Description |
|---|---|---|
| `cookie.name` | `PHPSESSID` | Name of the legacy session cookie |
| `cookie.encryption` | `none` | Cookie decryption: `none` or `laravel` |
| `database.connection` | `legacy` | DB connection name |
| `database.table` | `sessions` | Legacy sessions table |
| `database.columns.id` | `id` | Session ID column |
| `database.columns.payload` | `payload` | Payload column |
| `database.columns.time` | `last_activity` | Time column |
| `database.time.semantics` | `activity` | `activity` or `expires` |
| `database.time.format` | `timestamp` | `timestamp` or `datetime` |
| `lifetime` | `120` | Minutes a session remains valid |
| `payload.format` | `auto` | Payload encoding format |
| `app_key` | — | Legacy app's `APP_KEY` for decryption |
| `resolver.driver` | `auto` | `auto`, `key`, or `custom` |
| `resolver.key` | `user_id` | Dot-notation path for `key` driver |
| `resolver.class` | — | FQCN for `custom` driver |
| `context.carry_keys` | `[]` | Additional session keys to carry |
| `context.flash` | `false` | Whether to carry legacy flash data |
| `invalidation` | `after_write` | Session deletion strategy |

---

## Troubleshooting

### Sessions are not being bridged

1. Confirm the middleware is registered and in the `web` group
2. Run `legacy-bridge:verify --session-id=YOUR_ID` to test a real session
3. Listen to `LegacySessionBridgeFailed` and check `$event->reason`
4. Confirm `SESSION_DRIVER=database` and the new sessions table exists

### MissingCookie events on every request

The legacy cookie isn't reaching the middleware. Check:
- The cookie name in `LEGACY_BRIDGE_COOKIE` matches what the legacy app actually sets
- The cookie domain covers both apps — set `SESSION_DOMAIN=.yourdomain.com` if needed

### AmbiguousCookie events

The browser is sending multiple cookies with the same name, usually caused by overlapping
cookie scopes — for example, one cookie set for `/` and another for a sub-path, or cookies
set for both `.yourdomain.com` and `app.yourdomain.com`. Resolve it by ensuring the legacy
app sets its session cookie with a consistent `domain` and `path`, and that any stale
cookies from old deployments have been cleared.

### SessionNotFound despite valid cookie

Either the session has expired or the column names don't match your legacy table. Run the
verify command with a real session ID and check the `keys` output.

### AuthenticationFailed after user ID is resolved

The user ID exists in the legacy payload but not in your new app's users table. The most
likely cause is an ID mismatch from a data migration. Implement a custom resolver with an
ID mapping table.

### Cookie is arriving garbled (legacy Laravel app)

Set `LEGACY_BRIDGE_COOKIE_ENCRYPTION=laravel` and `LEGACY_BRIDGE_APP_KEY`. See
[Legacy Laravel applications](#legacy-laravel-applications).

### Payload is invalid / PayloadDecodeFailed

Set `LEGACY_BRIDGE_PAYLOAD_FORMAT` explicitly rather than relying on `auto`. If your legacy
app is Laravel with default settings, use `laravel`. Only use `encrypted` if `SESSION_ENCRYPT=true`
was explicitly set on the legacy app.

### Both cookies share the same name

Your legacy app (also Laravel) uses `laravel_session` and so does your new app. Set a distinct
`SESSION_COOKIE` on the new application. See [Step 4 — Cookie naming](#step-4--cookie-naming).

---

## Known Limitations

The following constraints apply to v0.1.0. They may be addressed in future releases.

### Session storage

Only database-backed legacy sessions are supported. The bridge reads session records from a SQL table via a configured database connection. Legacy applications storing sessions in files, Redis, Memcached, or other drivers are not supported in this release.

### Session propagation

The bridge identifies the legacy session exclusively via a request cookie. Applications that propagate session identity through request headers, URL parameters, or tokens are not supported.

### Auth guard

The bridge always authenticates into the default Laravel auth guard (`Auth::guard()`). If your application uses named guards (e.g. `admin`, `api`), the bridged user will be authenticated into the default guard only. Multi-guard bridging is not supported in this release.

### Single legacy source

One legacy database connection and sessions table can be configured per application. Bridging from multiple legacy applications simultaneously is not supported.

### Single legacy cookie

Only one cookie name can be configured. If your legacy application sets more than one session cookie, you must choose one as the bridge target.

### Cookie encryption

The bridge supports two cookie encryption modes: `none` (raw session ID) and `laravel` (Laravel-encrypted cookie). Other encryption schemes are not supported.

### User ID mapping

The user ID resolved from the legacy session payload is passed directly to `loginUsingId()`. This assumes legacy and new application user IDs are identical. If your migration involved re-seeding users or otherwise changing IDs, a custom resolver must handle the mapping before returning the ID.

### Rate limiting

The bridge does not apply rate limiting to bridge attempts. If your application requires rate limiting on unauthenticated requests, apply it at the application level using Laravel's built-in rate limiting middleware before the bridge middleware runs.

### Laravel version

Laravel 13 and PHP 8.3 or higher are required. Earlier versions are not supported.
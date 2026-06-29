# laravel-legacy-bridge — User Guide

This guide walks through a complete implementation of `laravel-legacy-bridge` for a real-world
Strangler Fig migration. By the end you will have a working session bridge that authenticates
users from your legacy application into your new Laravel app without forcing them to log in again.

---

## Before you start

You will need:

- A Laravel 11, or 12 application (the new app)
- A legacy PHP application (any framework, or plain PHP)
- Access to the legacy application's session database table
- The legacy session cookie name (commonly `PHPSESSID`)
- Knowledge of where the user ID is stored in your legacy session payload

If you are unsure about the last two points, the `legacy-bridge:verify` command will help you
discover them once the package is installed.

---

## Installation

```bash
composer require chr15k/laravel-legacy-bridge
php artisan legacy-bridge:install
```

The install command will:

- Publish `config/legacy-bridge.php`
- Create `app/Bridge/LegacyUserResolver.php` with a stub implementation
- Print the remaining setup steps

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
LEGACY_DB_HOST=127.0.0.1
LEGACY_DB_PORT=3306
LEGACY_DB_DATABASE=your_legacy_database
LEGACY_DB_USERNAME=your_user
LEGACY_DB_PASSWORD=your_password
LEGACY_SESSION_COOKIE=PHPSESSID
```

### Shared database

If both applications use the same database, no new connection is needed. Point the bridge at
your existing connection and specify the legacy sessions table name if it differs:

```dotenv
LEGACY_DB_CONNECTION=mysql
LEGACY_SESSION_TABLE=legacy_sessions
```

---

## Step 2 — Create the sessions table

Your new Laravel application needs its own sessions table. If you have not already created one:

```bash
php artisan make:session-table
php artisan migrate
```

Then set the session driver in `.env`:

```dotenv
SESSION_DRIVER=database
```

---

## Step 3 — Exclude the legacy cookie from encryption

Laravel encrypts all cookies by default. The legacy cookie was not set by your new application,
so it must be excluded from encryption — otherwise the session ID will be corrupted before
it reaches the bridge.

In `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->encryptCookies(except: [
        env('LEGACY_SESSION_COOKIE', 'PHPSESSID'),
    ]);
})
```

---

## Step 4 — Register the middleware

The bridge middleware must run on every web request, after Laravel's session has been started.

In `bootstrap/app.php` (Laravel 11+):

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->encryptCookies(except: [
        env('LEGACY_SESSION_COOKIE', 'PHPSESSID'),
    ]);

    $middleware->web(append: [
        \Chr15k\LegacyBridge\Http\Middleware\LegacySessionBridge::class,
    ]);
})
```

---

## Step 5 — Implement your resolver

The resolver is the only piece that is specific to your application. It tells the bridge where
the authenticated user ID lives in your legacy session payload.

Open `app/Bridge/LegacyUserResolver.php` (published by the install command):

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

`resolveId()` handles the common case where the value at a path is a serialized object with
an `id` property:

```php
// Legacy payload stores a User object at 'auth_user'
// resolveId() will read ->id from it automatically
return $payload->resolveId('auth_user');
```

**Common legacy structures and how to handle them:**

| Legacy app | Payload structure | Resolver |
|---|---|---|
| Plain PHP | `['user_id' => 42]` | `$payload->resolveId('user_id')` |
| Nested array | `['auth' => ['id' => 42]]` | `$payload->resolveId('auth.id')` |
| Serialized object | `['user' => User{id: 42}]` | `$payload->resolveId('user')` |
| Cartalyst Sentinel | `['cartalyst_sentinel' => ...]` | `$payload->resolveId('cartalyst_sentinel.id')` |
| Multiple guards | `['admin_id' => null, 'user_id' => 42]` | Check `has()` for each |

Then update `config/legacy-bridge.php` to use your resolver:

```php
'resolver' => [
    'driver' => 'custom',
    'class'  => \App\Bridge\LegacyUserResolver::class,
],
```

---

## Step 6 — Verify the configuration

Before routing any real traffic through the bridge, run the verify command:

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

The command will show you exactly what the bridge would do with that session — format detected,
payload keys found, user ID resolved, user existence confirmed — without actually authenticating
anyone or modifying any data.

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
  ✓ Resolver ready: custom
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

## Step 7 — Configure payload format

The `format` setting tells the bridge how to decode the legacy session payload.

Set `auto` to let the bridge detect it automatically (recommended during initial setup):

```dotenv
LEGACY_SESSION_FORMAT=auto
```

Once you know your format, set it explicitly for production:

```dotenv
LEGACY_SESSION_FORMAT=php_session   # Native PHP session encoding
LEGACY_SESSION_FORMAT=laravel       # Laravel base64(serialize()) format
LEGACY_SESSION_FORMAT=json          # JSON-encoded payload
LEGACY_SESSION_FORMAT=encrypted     # Laravel-encrypted (requires LEGACY_APP_KEY)
```

### Encrypted sessions

If your legacy application encrypted its sessions using Laravel's `Crypt` facade, set the
legacy application's `APP_KEY`:

```dotenv
LEGACY_SESSION_FORMAT=encrypted
LEGACY_APP_KEY=base64:your_legacy_app_key_here
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
    'flash'      => true, // carry legacy flash messages
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
LEGACY_SESSION_INVALIDATION=after_write
```

`after_write` is the recommended default. It ensures each legacy session can only be bridged
once — after the user crosses over, the legacy session is gone and they hold a standard Laravel
session.

Avoid `never` in production. It leaves session rows in the legacy database indefinitely.

---

## Monitoring the migration

Enable logging to track how many sessions are being bridged:

```dotenv
LEGACY_BRIDGE_LOGGING=true
LEGACY_BRIDGE_LOG_CHANNEL=stack
```

Each successful bridge logs:

```
legacy-bridge: session bridged {"user_id":42,"session_id":"abc123de…","format":"php_session"}
```

Watch this log channel during rollout. As users cross over, the rate of bridge events will
naturally decline. When they stop appearing entirely, every active user has been migrated and
the bridge is no longer doing any work.

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
3. Remove the `encryptCookies` exception for the legacy cookie
4. Remove the legacy database connection from `config/database.php`
5. Uninstall the package:

```bash
composer remove chr15k/laravel-legacy-bridge
```

The bridge is designed to leave nothing behind. Once removed, your application has no dependency
on the legacy sessions table or any bridge infrastructure.

---

## Troubleshooting

### Sessions are not being bridged

1. Confirm the middleware is registered and in the `web` group
2. Confirm the legacy cookie is excluded from `EncryptCookies`
3. Run `legacy-bridge:verify --session-id=YOUR_ID` to test a real session
4. Check the log channel for `legacy-bridge:` entries
5. Confirm `SESSION_DRIVER=database` and the sessions table exists

### Users are being logged out after bridging

The legacy session is being deleted (`after_write`) before Laravel has written its own. This
can happen if the Laravel session write fails — check for session configuration errors,
missing sessions table, or incorrect `SESSION_CONNECTION`.

### Cookie is arriving encrypted

The legacy cookie is not excluded from `EncryptCookies`. Add the cookie name to the `except`
list as described in Step 3.

### Resolver returns null

Run `legacy-bridge:verify --session-id=YOUR_ID` and check the `keys` field in the output.
This shows all keys present in the decoded payload. Update your resolver to point at the
correct key.

### Format detection fails

Set `LEGACY_SESSION_FORMAT` explicitly rather than using `auto`. Try each format in sequence
until the verify command shows a non-empty payload.
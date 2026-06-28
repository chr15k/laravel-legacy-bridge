<picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/header-dark.png">
    <img alt="Logo for Laravel Legacy Bridge package" src="art/header-light.png">
</picture>

<p align="center">
    <p align="center">
        <a href="https://github.com/chr15k/laravel-legacy-bridge/actions"><img alt="GitHub Workflow Status (master)" src="https://img.shields.io/github/actions/workflow/status/chr15k/laravel-legacy-bridge/main.yml"></a>
        <a href="https://packagist.org/packages/chr15k/laravel-legacy-bridge"><img alt="Total Downloads" src="https://img.shields.io/packagist/dt/chr15k/laravel-legacy-bridge"></a>
        <a href="https://packagist.org/packages/chr15k/laravel-legacy-bridge"><img alt="Latest Version" src="https://img.shields.io/packagist/v/chr15k/laravel-legacy-bridge"></a>
        <a href="https://packagist.org/packages/chr15k/laravel-legacy-bridge"><img alt="License" src="https://img.shields.io/github/license/chr15k/laravel-legacy-bridge"></a>
    </p>
</p>

------

**Laravel Legacy Bridge** is a stateful context continuity solution for Laravel [Strangler Fig](https://martinfowler.com/bliki/StranglerFigApplication.html) migrations.

When you migrate a legacy PHP application to Laravel incrementally, your users are already authenticated in the old app. This package bridges their session, auth state, and any other context they carry into the new Laravel application transparently — on their first request, without forcing a re-login.

---

## The problem

The Strangler Fig pattern lets you migrate feature by feature while both apps run simultaneously. The hard part is the boundary: a user who logged in on the legacy app is not authenticated in Laravel. Without a bridge, they hit a login wall on their first request to any Laravel-handled route.

**laravel-legacy-bridge** solves this by:

- Reading the legacy session cookie on every unauthenticated request
- Fetching and decoding the legacy session payload from the legacy database
- Resolving the user ID using a configurable strategy
- Calling `Auth::loginUsingId()` to authenticate them in Laravel
- Optionally carrying additional session context (locale, cart ID, flash data) across the boundary
- Invalidating the legacy session after a successful bridge

The bridge runs once per user. After their first request, they hold a standard Laravel session and the legacy database is never touched again for that user.

---

## Requirements

- PHP 8.3+
- Laravel 12 or 13

---

## Installation

```bash
composer require chr15k/laravel-legacy-bridge
```

Publish the config and resolver stub:

```bash
php artisan legacy-bridge:install
```

---

## Configuration

### 1. Add the legacy database connection

In `config/database.php`:

```php
'connections' => [
    // ...
    'legacy' => [
        'driver'   => 'mysql',
        'host'     => env('LEGACY_DB_HOST', '127.0.0.1'),
        'database' => env('LEGACY_DB_DATABASE'),
        'username' => env('LEGACY_DB_USERNAME'),
        'password' => env('LEGACY_DB_PASSWORD'),
        'charset'  => 'utf8mb4',
        'prefix'   => '',
    ],
],
```

> [!NOTE]
> If both applications share the same database, set `LEGACY_DB_CONNECTION` to your default connection name and skip adding a new connection entry.

### 2. Set environment variables

```dotenv
LEGACY_DB_CONNECTION=legacy
LEGACY_DB_HOST=127.0.0.1
LEGACY_DB_DATABASE=your_legacy_db
LEGACY_DB_USERNAME=your_user
LEGACY_DB_PASSWORD=your_password

LEGACY_SESSION_COOKIE=PHPSESSID
LEGACY_SESSION_TABLE=sessions
LEGACY_SESSION_FORMAT=auto
```

### 3. Register the middleware

In `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \Chr15k\LegacyBridge\Middleware\LegacySessionBridge::class,
    ]);
})
```

> [!NOTE]
> Place the middleware **after** `StartSession` in the stack so Laravel's own session is initialised before the bridge runs.

### 4. Implement your resolver

The install command publishes a stub to `app/Bridge/LegacyUserResolver.php`. Fill in the `resolve()` method to match your legacy application's session structure:

```php
namespace App\Bridge;

use Chr15k\LegacyBridge\Payload\LegacyPayload;
use Chr15k\LegacyBridge\Resolvers\Contracts\LegacyUserResolver as Contract;

class LegacyUserResolver implements Contract
{
    public function resolve(LegacyPayload $payload): ?int
    {
        // Flat structure: session stores user_id directly
        return $payload->resolveId('user_id');

        // Nested structure
        // return $payload->resolveId('auth.user.id');

        // Try multiple paths
        // return $payload->resolveId('auth.user.id')
        //     ?? $payload->resolveId('user_id');
    }
}
```

Then update `config/legacy-bridge.php` to point to it:

```php
'resolver' => [
    'driver' => 'custom',
    'class'  => \App\Bridge\LegacyUserResolver::class,
],
```

### 5. Verify the configuration

Before routing any real traffic through the bridge:

```bash
php artisan legacy-bridge:verify
```

To test against a real session ID from your legacy database:

```bash
php artisan legacy-bridge:verify --session-id=abc123def456
```

This will output exactly what the package would do with that session — format detected, payload keys found, user ID resolved, user existence confirmed — without authenticating anyone.

---

## Payload format

The `LegacyPayload` object wraps the decoded session and provides a safe API for traversal:

```php
// Dot-notation access
$payload->get('user_id');             // top-level key
$payload->get('auth.user.id');        // nested key
$payload->get('missing', 'default'); // with fallback

// Safe ID resolution — handles scalar, array['id'], or object->id
$payload->resolveId('user_id');
$payload->resolveId('cartalyst.sentinel');

// Existence check
$payload->has('cart_id');

// All keys
$payload->all();
$payload->only(['locale', 'timezone']);

// Detected format
$payload->format(); // 'php_session' | 'json' | 'laravel' | 'encrypted'
```

### Supported formats

| Format | Description |
|---|---|
| `auto` | Detects the format automatically (recommended starting point) |
| `php_session` | Native PHP session encoding (`key\|serialized;`) |
| `json` | JSON-encoded payload, raw or base64-wrapped |
| `laravel` | Laravel's `base64(serialize($array))` format |
| `encrypted` | Laravel-encrypted sessions — requires `LEGACY_APP_KEY` |

For encrypted sessions, set the legacy application's `APP_KEY` in your environment:

```dotenv
LEGACY_SESSION_FORMAT=encrypted
LEGACY_APP_KEY=base64:your_legacy_app_key_here
```

---

## Built-in resolver drivers

If your legacy application uses a common structure, you may not need a custom resolver:

```php
// config/legacy-bridge.php

// Auto: tries known patterns in sequence (default)
'resolver' => ['driver' => 'auto'],

// Key: dot-notation lookup at a specific path
'resolver' => ['driver' => 'key', 'key' => 'user_id'],

// Custom: your own implementation
'resolver' => ['driver' => 'custom', 'class' => \App\Bridge\LegacyUserResolver::class],
```

> [!NOTE]
> The `auto` driver tries a sequence of known paths covering plain PHP apps, Cartalyst Sentinel, Cartalyst Sentry, and older Laravel auth session formats. Use it as a starting point, then switch to `key` or `custom` once you know your payload structure.

---

## Carrying additional context

To carry session keys beyond auth state (locale, timezone, cart ID, etc.), set `carry_keys` in the config:

```php
'context' => [
    'carry_keys' => ['locale', 'timezone', 'cart_id'],
    'flash'      => true, // also carry legacy flash data
],
```

For full control, bind a `LegacyContextResolver` in a service provider:

```php
use Chr15k\LegacyBridge\Resolvers\Contracts\LegacyContextResolver;

$this->app->singleton(LegacyContextResolver::class, function () {
    return new class implements LegacyContextResolver {
        public function resolve(?int $userId, LegacyPayload $payload): array
        {
            return $payload->only(['locale', 'timezone']);
        }
    };
});
```

---

## Invalidation strategies

Control what happens to the legacy session after a successful bridge:

| Strategy | Behaviour |
|---|---|
| `after_write` | Delete legacy session after Laravel writes its own **(recommended)** |
| `immediate` | Delete legacy session before passing to `next()` |
| `never` | Leave legacy session intact — useful when the legacy app must remain authoritative during the transition window |

```dotenv
LEGACY_SESSION_INVALIDATION=after_write
```

---

## Cookie alignment

Both cookies must be able to reach the browser simultaneously during the transition window. Keep the names distinct:

```dotenv
SESSION_COOKIE=laravel_session    # Laravel's session cookie
LEGACY_SESSION_COOKIE=PHPSESSID  # Your legacy app's cookie (read-only by the bridge)
```

If both apps sit on different subdomains, set the session domain so both cookies are sent on cross-subdomain requests:

```dotenv
SESSION_DOMAIN=.yourdomain.com
```

The `legacy-bridge:verify` command will warn you if both cookies share the same name.

---

## Shared database

If both applications use the same database, set `LEGACY_DB_CONNECTION` to your default connection and point `LEGACY_SESSION_TABLE` at the legacy sessions table (if it differs from `sessions`):

```dotenv
LEGACY_DB_CONNECTION=mysql
LEGACY_SESSION_TABLE=legacy_sessions
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

When `legacy-bridge: session bridged` stops appearing in your logs, the migration window is over. At that point:

1. Remove `LegacySessionBridge` from your middleware stack
2. Remove the legacy DB connection from `config/database.php`
3. Uninstall the package: `composer remove chr15k/laravel-legacy-bridge`

---

## Testing

```bash
composer test
```

---

## Configuration reference

```php
// config/legacy-bridge.php

return [
    'cookie'         => env('LEGACY_SESSION_COOKIE', 'PHPSESSID'),
    'connection'     => env('LEGACY_DB_CONNECTION', 'legacy'),
    'table'          => env('LEGACY_SESSION_TABLE', 'sessions'),
    'lifetime'       => env('LEGACY_SESSION_LIFETIME', 120),
    'format'         => env('LEGACY_SESSION_FORMAT', 'auto'),
    'legacy_app_key' => env('LEGACY_APP_KEY'),

    'resolver' => [
        'driver' => env('LEGACY_RESOLVER_DRIVER', 'auto'),
        'key'    => env('LEGACY_RESOLVER_KEY', 'user_id'),
        'class'  => env('LEGACY_RESOLVER_CLASS', \App\Bridge\LegacyUserResolver::class),
    ],

    'context' => [
        'carry_keys' => [],
        'flash'      => false,
    ],

    'invalidation' => env('LEGACY_SESSION_INVALIDATION', 'after_write'),

    'logging' => [
        'enabled' => env('LEGACY_BRIDGE_LOGGING', true),
        'channel' => env('LEGACY_BRIDGE_LOG_CHANNEL', null),
    ],
];
```

---

## Security & Best Practice

### The bridge does not introduce new attack vectors

The package authenticates users based on a session ID that must already exist in your legacy
database. An attacker cannot forge or guess their way in — a valid row in the legacy sessions
table is required. The security posture of the bridge is therefore equivalent to the legacy
application itself: if the legacy app was secure, the bridge is secure.

The realistic threats are the same ones that applied to your legacy app before the migration began.

### Session hijacking

If an attacker intercepts a real user's `PHPSESSID` cookie (via network sniffing on plain HTTP,
XSS, or similar), they can present it to the Laravel app and be authenticated as that user. This
is standard session hijacking — not specific to the bridge.

**Mitigation:** enforce HTTPS across both applications. The legacy cookie is excluded from
Laravel's `EncryptCookies` middleware by design (it was not set by Laravel), so it travels as
plain text. HTTPS is non-negotiable.

### Legacy database access

If an attacker gains write access to the legacy sessions table, they can insert a row with a
known ID and authenticate as any user. At that point the session bridge is the least of your
concerns — treat legacy DB access as a critical breach regardless.

### Keep the migration window short

Every day the bridge is active is another day the legacy sessions table is part of your
application's trust boundary. Monitor the `legacy-bridge: session bridged` log entries — when
they stop appearing, the migration is complete. Remove the middleware and uninstall the package
as soon as possible.

```dotenv
LEGACY_BRIDGE_LOGGING=true
LEGACY_BRIDGE_LOG_CHANNEL=stack
```

### Invalidation strategy

The default `after_write` strategy deletes the legacy session immediately after Laravel writes
its own. This means each legacy session can only ever be bridged once — a stolen session ID
cannot be replayed after the legitimate user has already been migrated.

Avoid `never` in production. It leaves legacy session rows in the database indefinitely,
extending the window in which a stolen session ID remains usable.

### User eligibility

The package calls `Auth::loginUsingId()` without checking whether the user is active, verified,
or permitted to access the new application. Any such checks should be applied via Laravel's
authentication events or your `User` model:

```php
use Illuminate\Auth\Events\Login;

protected $listen = [
    Login::class => [
        EnsureUserIsActive::class,
    ],
];
```

### Use an explicit resolver in production

The `auto` resolver tries a sequence of known payload paths. Switch to `key` or `custom` before
going to production so the user identity path is explicit and unambiguous:

```dotenv
LEGACY_RESOLVER_DRIVER=key
LEGACY_RESOLVER_KEY=user_id
```

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT. See [LICENSE](LICENSE.md).

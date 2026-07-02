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

**Laravel Legacy Bridge** solves this by:

- Reading the legacy session cookie on every unauthenticated request
- Fetching and decoding the legacy session payload from the legacy database
- Resolving the user ID using a configurable strategy
- Calling `Auth::loginUsingId()` to authenticate them in Laravel
- Optionally carrying additional session context (locale, cart ID, flash data) across the boundary
- Invalidating the legacy session after a successful bridge

The bridge runs once per user. After their first request, they hold a standard Laravel session and the legacy database is never touched again for that user.

> [!NOTE]
> Laravel Legacy Bridge currently supports database-backed sessions. This provides a simple, predictable, and well-understood integration for incremental migrations. Additional session backends, such as Redis, may be added in future releases if there is sufficient demand.

---

## Requirements

- PHP 8.3+
- Laravel 12 or 13

---

## Quickstart

```bash
composer require chr15k/laravel-legacy-bridge
php artisan legacy-bridge:install
```

The install command publishes `config/legacy-bridge.php` and prints the remaining setup steps.

Add your legacy database credentials to `.env`:

```dotenv
LEGACY_BRIDGE_DB_CONNECTION=legacy
LEGACY_BRIDGE_DB_HOST=127.0.0.1
LEGACY_BRIDGE_DB_DATABASE=your_legacy_db
LEGACY_BRIDGE_DB_USERNAME=your_user
LEGACY_BRIDGE_DB_PASSWORD=your_password

LEGACY_BRIDGE_COOKIE=PHPSESSID
LEGACY_BRIDGE_SESSION_TABLE=sessions
LEGACY_BRIDGE_PAYLOAD_FORMAT=auto
```

Add the legacy connection to `config/database.php`:

```php
'connections' => [
    // ...
    'legacy' => [
        'driver'   => 'mysql',
        'host'     => env('LEGACY_BRIDGE_DB_HOST', '127.0.0.1'),
        'database' => env('LEGACY_BRIDGE_DB_DATABASE'),
        'username' => env('LEGACY_BRIDGE_DB_USERNAME'),
        'password' => env('LEGACY_BRIDGE_DB_PASSWORD'),
        'charset'  => 'utf8mb4',
        'prefix'   => '',
    ],
],
```

> [!NOTE]
> If both applications share the same database, set `LEGACY_BRIDGE_DB_CONNECTION` to your default connection name and skip adding a new connection entry. See the [User Guide](GUIDE.md#shared-database) for details.

Register the middleware in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \Chr15k\LegacyBridge\Http\Middleware\LegacySessionBridge::class,
    ]);
})
```

> [!NOTE]
> Cookie encryption exclusion for the legacy cookie is handled automatically by the package's service provider — no extra configuration needed.

Verify your setup before routing real traffic:

```bash
php artisan legacy-bridge:verify
php artisan legacy-bridge:verify --session-id=a_real_session_id
```

That's it for the common case — the bundled `auto` resolver covers most plain PHP and standard Laravel legacy session structures out of the box. **A custom resolver is optional** and only needed if `auto` can't find your user ID (or your User IDs need mapping); see [Implementing a custom resolver](GUIDE.md#step-5--implement-a-resolver-optional) in the guide.

---

## Documentation

Full setup, configuration reference, and troubleshooting live in the **[User Guide](GUIDE.md)**:

- [Configuring the database connection (separate or shared)](GUIDE.md#step-1--configure-the-database-connection)
- [Creating the new sessions table](GUIDE.md#step-2--create-the-sessions-table)
- [Registering the middleware](GUIDE.md#step-3--register-the-middleware)
- [Implementing a custom resolver (optional)](GUIDE.md#step-5--implement-a-resolver-optional)
- [Verifying your configuration](GUIDE.md#step-6--verify-the-configuration)
- [Payload formats](GUIDE.md#payload-format)
- [Legacy Laravel applications (cookie & payload encryption)](GUIDE.md#legacy-laravel-applications)
- [Carrying additional context](GUIDE.md#carrying-additional-context)
- [Invalidation strategies](GUIDE.md#invalidation-strategies)
- [Cookie alignment](GUIDE.md#cookie-alignment)
- [Monitoring the migration](GUIDE.md#monitoring-the-migration)
- [Removing the bridge](GUIDE.md#removing-the-bridge)
- [Troubleshooting](GUIDE.md#troubleshooting)

---

## Configuration reference

```php
// config/legacy-bridge.php

return [
    'cookie'             => env('LEGACY_BRIDGE_COOKIE', 'PHPSESSID'),
    'connection'         => env('LEGACY_BRIDGE_DB_CONNECTION', 'legacy'),
    'table'              => env('LEGACY_BRIDGE_SESSION_TABLE', 'sessions'),
    'lifetime'           => env('LEGACY_BRIDGE_LIFETIME', 120),
    'format'             => env('LEGACY_BRIDGE_PAYLOAD_FORMAT', 'auto'),
    'cookie_encryption'  => env('LEGACY_BRIDGE_COOKIE_ENCRYPTED', 'none'), // 'none' | 'laravel'
    'app_key'     => env('LEGACY_BRIDGE_APP_KEY'),

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

See the [Configuration reference in the User Guide](GUIDE.md#configuration-reference) for a full explanation of every key.

---

## Security

### Trust model

The security primitive is the session cookie. Possession of a valid legacy session cookie that matches a row in the legacy sessions table is proof that the legacy application already authenticated that user. The bridge honours that existing authentication decision — it does not re-authenticate, it continues a session across the application boundary.

This is the same trust model as any session-based application. The bridge adds one extra hop (the legacy DB lookup) but the security primitive is identical to what the legacy app itself used. The bridge does not fundamentally change the trust model. It relies on the same session security assumptions that already existed in the legacy application.

### HTTPS is required

The legacy cookie is excluded from Laravel's `EncryptCookies` middleware by design. It was created by the legacy application, so Laravel does not encrypt or modify it. Protect it with HTTPS across both applications.

### Payload trust

Laravel's own session handler encrypts and signs the session payload using `APP_KEY`. Legacy payloads have no equivalent—they are trusted because the session ID matches a row in the legacy session store, which is itself authenticated by the session cookie. An attacker with the ability to write arbitrary session rows to the legacy database could already construct fraudulent sessions, so payload-level verification would not materially change the threat model. The session cookie remains the trust anchor.

Keep `carry_keys` to the minimum necessary — the bridge resolves a user ID and calls `Auth::loginUsingId()`; treat everything else in the legacy payload as untrusted input.

### Invalidation

The default `after_write` strategy deletes the legacy session after Laravel writes its own, meaning each legacy session can only be bridged once. Avoid `never` in production unless you have a specific migration requirement, as legacy sessions remain valid until they expire or are removed by the legacy application.

### Keep the migration window short

Monitor the `legacy-bridge: session bridged` log entries. Once bridging activity has ceased and you have confirmed users are authenticating directly with Laravel, remove the middleware and uninstall the package.

### User eligibility

`Auth::loginUsingId()` does not check whether a user is active, verified, or banned. Apply any such checks via Laravel's authentication events — see [Handling user eligibility](GUIDE.md#handling-user-eligibility) in the guide.

### Use an explicit resolver in production

The `auto` resolver tries several known payload paths, making it convenient during setup. In production, prefer `key` or `custom` so the user identity is resolved from a single, explicit location:

```dotenv
LEGACY_BRIDGE_RESOLVER_DRIVER=key
LEGACY_BRIDGE_RESOLVER_KEY=user_id
```

---

## Testing

```bash
composer test
```

---

## User Guide

See [GUIDE.md](GUIDE.md) for the full implementation walkthrough, configuration reference, and troubleshooting steps.

## Changelog

See [CHANGELOG.md](CHANGELOG.md)

## License

MIT. See [LICENSE](LICENSE.md)
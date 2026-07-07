# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## 0.1.0 - 2026-07-07

### Added

- Initial release

**v0.1.0 constraints**

- **Database sessions only** — the bridge reads from a SQL sessions table. File-based, Redis, Memcached, and other session drivers are not supported.
- **Single legacy source** — one legacy database connection and sessions table per application. Bridging from multiple legacy apps simultaneously is not supported.
- **Cookie-based session propagation only** — the bridge identifies sessions via a request cookie. Header tokens, URL parameters, and other propagation methods are not supported.
- **Default auth guard only** — `Auth::guard()` (your app's default guard) is used. Bridging into a named guard (e.g. `admin`, `api`) is not configurable in this release.
- **Single legacy cookie** — one cookie name per application. If your legacy app sets multiple session cookies you must choose one.
- **Web middleware stack only** — the bridge establishes a stateful Laravel session. Stateless / API requests are not a supported use case.
- **Laravel 13 and PHP 8.3+** — no support for earlier versions.
- **Cookie encryption: `none` or `laravel` only** — no other cookie encryption schemes are supported.
- **Direct user ID mapping assumed** — the resolved user ID is passed directly to `loginUsingId()`. If legacy and new user IDs differ, a custom resolver must handle the mapping.
- **No built-in rate limiting** — bridge attempts are not rate-limited; this is left to application-level middleware.
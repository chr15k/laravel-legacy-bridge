<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge;

use Chr15k\LegacyBridge\Contracts\LegacyUserResolver;

final class Config
{
    /**
     * @return array{
     *     cookie: string,
     *     connection: string,
     *     table: string,
     *     lifetime: int,
     *     format: string,
     *     legacy_app_key: string|null,
     *     resolver: array{
     *         driver: string,
     *         key: string,
     *         class: class-string<LegacyUserResolver>|null,
     *     },
     *     context: array{
     *         carry_keys: list<string>,
     *         flash: bool,
     *     },
     *     invalidation: string,
     *     logging: array{
     *         enabled: bool,
     *         channel: string|null,
     *     },
     * }
     */
    public static function all(): array
    {
        /** @var array{cookie: string, connection: string, table: string, lifetime: int, format: string, legacy_app_key: string|null, resolver: array{driver: string, key: string, class: class-string<LegacyUserResolver>|null}, context: array{carry_keys: list<string>, flash: bool}, invalidation: string, logging: array{enabled: bool, channel: string|null}} */
        return config('legacy-bridge');
    }

    public static function cookie(): string
    {
        return self::string('legacy-bridge.cookie', 'PHPSESSID');
    }

    public static function connection(): string
    {
        return self::string('legacy-bridge.connection', 'legacy');
    }

    public static function table(): string
    {
        return self::string('legacy-bridge.table', 'sessions');
    }

    public static function lifetime(): int
    {
        $value = config('legacy-bridge.lifetime', 120);

        return is_int($value) ? $value : (int) (is_string($value) ? $value : 120);
    }

    public static function format(): string
    {
        return self::string('legacy-bridge.format', 'auto');
    }

    public static function legacyAppKey(): ?string
    {
        $value = config('legacy-bridge.legacy_app_key');

        return is_string($value) ? $value : null;
    }

    public static function invalidation(): string
    {
        return self::string('legacy-bridge.invalidation', 'after_write');
    }

    /**
     * @return array{
     *     driver: string,
     *     key: string,
     *     class: class-string<LegacyUserResolver>|null,
     * }
     */
    public static function resolver(): array
    {
        /** @var array{driver: string, key: string, class: class-string<LegacyUserResolver>|null} */
        return config('legacy-bridge.resolver');
    }

    public static function resolverDriver(): string
    {
        return self::string('legacy-bridge.resolver.driver', 'auto');
    }

    public static function resolverKey(): string
    {
        return self::string('legacy-bridge.resolver.key', 'user_id');
    }

    /**
     * @return class-string<LegacyUserResolver>|null
     */
    public static function resolverClass(): ?string
    {
        $value = config('legacy-bridge.resolver.class');

        return is_string($value) ? $value : null; // @phpstan-ignore-line
    }

    /**
     * @return list<string>
     */
    public static function contextCarryKeys(): array
    {
        /** @var list<string> */
        return config('legacy-bridge.context.carry_keys', []);
    }

    public static function contextFlash(): bool
    {
        $value = config('legacy-bridge.context.flash', false);

        return is_bool($value) ? $value : (bool) $value;
    }

    public static function loggingEnabled(): bool
    {
        $value = config('legacy-bridge.logging.enabled', true);

        return is_bool($value) ? $value : (bool) $value;
    }

    public static function loggingChannel(): ?string
    {
        $value = config('legacy-bridge.logging.channel');

        return is_string($value) ? $value : null;
    }

    private static function string(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }
}

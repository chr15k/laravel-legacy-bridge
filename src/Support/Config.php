<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Support;

use Chr15k\LegacyBridge\Contracts\LegacyUserResolver;
use Chr15k\LegacyBridge\Enums\CookieEncryption;
use Chr15k\LegacyBridge\Enums\InvalidationStrategy;
use Chr15k\LegacyBridge\Enums\PayloadFormat;
use Chr15k\LegacyBridge\Enums\SessionTimeFormat;
use Chr15k\LegacyBridge\Enums\SessionTimeSemantics;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;

final readonly class Config
{
    public function __construct(private Repository $config) {}

    /*
    |--------------------------------------------------------------------------
    | Config accessors
    |--------------------------------------------------------------------------
    */

    public function cookie(): string
    {
        $config = $this->config->get('legacy-bridge.cookie.name', 'PHPSESSID');

        if (! is_string($config)) {
            return 'PHPSESSID';
        }

        return $config;
    }

    public function connection(): string
    {
        $config = $this->config->get('legacy-bridge.database.connection', 'legacy');

        if (! is_string($config)) {
            return 'legacy';
        }

        return $config;
    }

    public function table(): string
    {
        $config = $this->config->get('legacy-bridge.database.table', 'sessions');

        if (! is_string($config)) {
            return 'sessions';
        }

        return $config;
    }

    /**
     * @return array{id: string, payload: string, time: string}
     */
    public function sessionColumns(): array
    {
        /** @var array{id: string, payload: string, time: string}|null */
        $config = $this->config->get('legacy-bridge.database.columns');

        if (! is_array($config)) {
            return [
                'id'      => 'id',
                'payload' => 'payload',
                'time'    => 'last_activity',
            ];
        }

        return $config;
    }

    public function sessionIdPrefix(): string
    {
        $config = $this->config->get('legacy-bridge.database.id_prefix', '');

        return is_string($config) ? $config : '';
    }

    public function sessionTimeSemantics(): SessionTimeSemantics
    {
        $default = SessionTimeSemantics::Activity;

        $value = $this->config->get('legacy-bridge.database.time.semantics', $default->value);

        if (! is_string($value)) {
            return $default;
        }

        return SessionTimeSemantics::tryFrom($value) ?? $default;
    }

    public function sessionTimeFormat(): SessionTimeFormat
    {
        $default = SessionTimeFormat::Timestamp;

        $value = $this->config->get('legacy-bridge.database.time.format', $default->value);

        if (! is_string($value)) {
            return $default;
        }

        return SessionTimeFormat::tryFrom($value) ?? $default;
    }

    public function sessionTimeZone(): DateTimeZone
    {
        $value = $this->config->get('legacy-bridge.database.time.timezone', 'UTC');

        return new DateTimeZone(is_string($value) ? $value : 'UTC');
    }

    public function lifetime(): int
    {
        $value = $this->config->get('legacy-bridge.lifetime', 120);

        return is_int($value) ? $value : (int) (is_string($value) ? $value : 120);
    }

    public function cookieEncryption(): CookieEncryption
    {
        $default = CookieEncryption::None;

        $value = $this->config->get('legacy-bridge.cookie.encryption', $default->value);

        if (! is_string($value)) {
            return $default;
        }

        return CookieEncryption::tryFrom($value) ?? $default;
    }

    public function format(): PayloadFormat
    {
        $default = PayloadFormat::Auto;

        $value = $this->config->get('legacy-bridge.payload.format', $default->value);

        if (! is_string($value)) {
            return $default;
        }

        return PayloadFormat::tryFrom($value) ?? $default;
    }

    public function legacyAppKey(): ?string
    {
        $config = $this->config->get('legacy-bridge.app_key');

        if (! is_string($config)) {
            return null;
        }

        return $config;
    }

    public function invalidationStrategy(): InvalidationStrategy
    {
        $default = InvalidationStrategy::AfterWrite;

        $value = $this->config->get('legacy-bridge.invalidation_strategy', $default->value);

        if (! is_string($value)) {
            return $default;
        }

        return InvalidationStrategy::tryFrom($value) ?? $default;
    }

    /**
     * @return array{
     *     driver: ?string,
     *     key: ?string,
     *     class: class-string<LegacyUserResolver>|null,
     * }
     */
    public function resolver(): array
    {
        /**
         * @var array{
         *   driver: ?string,
         *   key: ?string,
         *   class: class-string<LegacyUserResolver>|null
         * }|null $config
         */
        $config = $this->config->get('legacy-bridge.resolver');

        if (! is_array($config)) {
            return [
                'driver' => 'auto',
                'key'    => 'user_id',
                'class'  => null,
            ];
        }

        return $config;
    }

    /**
     * @return list<string>
     */
    public function contextCarryKeys(): array
    {
        /** @var list<string>|null */
        $config = $this->config->get('legacy-bridge.context.carry_keys', []);

        if (! is_array($config)) {
            return [];
        }

        return $config;
    }

    public function contextFlash(): bool
    {
        $config = $this->config->get('legacy-bridge.context.flash', false);

        if (! is_bool($config)) {
            return false;
        }

        return $config;
    }
}

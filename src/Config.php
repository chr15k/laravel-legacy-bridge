<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge;

use Chr15k\LegacyBridge\Contracts\LegacyUserResolver;
use Chr15k\LegacyBridge\Enums\CookieEncryption;
use Chr15k\LegacyBridge\Enums\InvalidationStrategy;
use Chr15k\LegacyBridge\Enums\PayloadFormat;
use Chr15k\LegacyBridge\Enums\SessionTimeFormat;
use Chr15k\LegacyBridge\Enums\SessionTimeSemantics;
use Illuminate\Contracts\Config\Repository;

final readonly class Config
{
    public function __construct(private Repository $config) {}

    /*
    |--------------------------------------------------------------------------
    | Config accessors
    |--------------------------------------------------------------------------
    */

    public function cookie(): ?string
    {
        return $this->string('legacy-bridge.cookie.name', 'PHPSESSID');
    }

    public function connection(): string
    {
        return $this->string('legacy-bridge.database.connection', 'legacy');
    }

    public function table(): string
    {
        return $this->string('legacy-bridge.database.table', 'sessions');
    }

    /**
     * @return array{id: string, payload: string, time: string}
     */
    public function sessionColumns(): array
    {
        /** @var array{id: string, payload: string, time: string} */
        return config('legacy-bridge.database.columns');
    }

    public function sessionTimeSemantics(): SessionTimeSemantics
    {
        return config('legacy-bridge.database.time.semantics');
    }

    public function sessionTimeFormat(): SessionTimeFormat
    {
        return config('legacy-bridge.database.time.format');
    }

    public function lifetime(): int
    {
        $value = $this->config->get('legacy-bridge.lifetime', 120);

        return is_int($value) ? $value : (int) (is_string($value) ? $value : 120);
    }

    public function cookieEncryption(): CookieEncryption
    {
        return CookieEncryption::tryFrom($this->string('legacy-bridge.cookie.encryption'))
            ?? CookieEncryption::None;
    }

    public function format(): PayloadFormat
    {
        return PayloadFormat::tryFrom($this->string('legacy-bridge.payload.format'))
            ?? PayloadFormat::Auto;
    }

    public function legacyAppKey(): ?string
    {
        return $this->string('legacy-bridge.app_key');
    }

    public function invalidation(): InvalidationStrategy
    {
        return InvalidationStrategy::tryFrom($this->string('legacy-bridge.invalidation'))
            ?? InvalidationStrategy::AfterWrite;
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
        /** @var array{driver: ?string, key: ?string, class: class-string<LegacyUserResolver>|null} */
        return $this->config->get('legacy-bridge.resolver');
    }

    /**
     * @return list<string>
     */
    public function contextCarryKeys(): array
    {
        /** @var list<string> */
        return $this->config->get('legacy-bridge.context.carry_keys', []);
    }

    public function contextFlash(): ?bool
    {
        return $this->bool('legacy-bridge.context.flash', false);
    }

    public function loggingEnabled(): ?bool
    {
        return $this->bool('legacy-bridge.logging.enabled', true);
    }

    public function loggingChannel(): ?string
    {
        return $this->string('legacy-bridge.logging.channel');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function shouldDecryptLegacySession(): bool
    {
        return $this->format() === 'encrypted' && $this->legacyAppKey() !== null;
    }

    private function bool(string $key, ?bool $default = null): ?bool
    {
        $value = $this->config->get($key, $default);

        return is_bool($value) ? $value : $default;
    }

    private function string(string $key, ?string $default = null): ?string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) ? $value : $default;
    }
}

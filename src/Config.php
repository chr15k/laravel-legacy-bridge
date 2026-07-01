<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge;

use Chr15k\LegacyBridge\Contracts\LegacyUserResolver;
use Chr15k\LegacyBridge\Integrations\Laravel;
use Illuminate\Contracts\Config\Repository;

final readonly class Config
{
    public function __construct(private Repository $config) {}

    /*
    |--------------------------------------------------------------------------
    | Config accessors
    |--------------------------------------------------------------------------
    */

    public function integration(): ?string
    {
        return $this->string('legacy-bridge.integration', Laravel::class);
    }

    public function cookie(): ?string
    {
        return $this->string('legacy-bridge.cookie.name', 'PHPSESSID');
    }

    public function connection(): ?string
    {
        return $this->string('legacy-bridge.connection', 'legacy');
    }

    public function table(): ?string
    {
        return $this->string('legacy-bridge.table', 'sessions');
    }

    public function lifetime(): int
    {
        $value = $this->config->get('legacy-bridge.lifetime', 120);

        return is_int($value) ? $value : (int) (is_string($value) ? $value : 120);
    }

    public function cookieEncryption(): ?bool
    {
        return $this->bool('legacy-bridge.cookie.encrypted', false);
    }

    public function format(): ?string
    {
        return $this->string('legacy-bridge.payload.format', 'auto');
    }

    public function legacyAppKey(): ?string
    {
        return $this->string('legacy-bridge.app_key');
    }

    public function invalidation(): ?string
    {
        return $this->string('legacy-bridge.invalidation', 'after_write');
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

    public function shouldInvalidateAfterWrite(): bool
    {
        return $this->invalidation() === 'after_write';
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

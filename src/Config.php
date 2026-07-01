<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge;

use Chr15k\LegacyBridge\Contracts\LegacyUserResolver;
use Chr15k\LegacyBridge\Integrations\Laravel;
use Illuminate\Contracts\Config\Repository;

final readonly class Config
{
    public function __construct(private Repository $config)
    {
        //
    }

    public function integration(): string
    {
        return $this->string('legacy-bridge.integration', Laravel::class);
    }

    public function cookie(): string
    {
        return $this->string('legacy-bridge.cookie.name', 'PHPSESSID');
    }

    public function connection(): string
    {
        return $this->string('legacy-bridge.connection', 'legacy');
    }

    public function table(): string
    {
        return $this->string('legacy-bridge.table', 'sessions');
    }

    public function lifetime(): int
    {
        $value = $this->config->get('legacy-bridge.lifetime', 120);

        return is_int($value) ? $value : (int) (is_string($value) ? $value : 120);
    }

    public function shouldDecryptLegacySession(): bool
    {
        return $this->format() === 'encrypted' && $this->legacyAppKey() !== null;
    }

    public function cookieEncryption(): bool
    {
        $value = $this->config->get('legacy-bridge.cookie.encrypted', false);

        return is_bool($value) ? $value : (bool) $value;
    }

    public function format(): string
    {
        return $this->string('legacy-bridge.payload.format', 'auto');
    }

    public function legacyAppKey(): ?string
    {
        $value = $this->config->get('legacy-bridge.app_key');

        return is_string($value) ? $value : null;
    }

    public function invalidation(): string
    {
        return $this->string('legacy-bridge.invalidation', 'after_write');
    }

    /**
     * @return array{
     *     driver: string,
     *     key: string,
     *     class: class-string<LegacyUserResolver>|null,
     * }
     */
    public function resolver(): array
    {
        /** @var array{driver: string, key: string, class: class-string<LegacyUserResolver>|null} */
        return $this->config->get('legacy-bridge.resolver');
    }

    public function resolverDriver(): string
    {
        return $this->string('legacy-bridge.resolver.driver', 'auto');
    }

    public function resolverKey(): string
    {
        return $this->string('legacy-bridge.resolver.key', 'user_id');
    }

    /**
     * @return class-string<LegacyUserResolver>|null
     */
    public function resolverClass(): ?string
    {
        $value = $this->config->get('legacy-bridge.resolver.class');

        return is_string($value) ? $value : null; // @phpstan-ignore-line
    }

    /**
     * @return list<string>
     */
    public function contextCarryKeys(): array
    {
        /** @var list<string> */
        return $this->config->get('legacy-bridge.context.carry_keys', []);
    }

    public function contextFlash(): bool
    {
        $value = $this->config->get('legacy-bridge.context.flash', false);

        return is_bool($value) ? $value : (bool) $value;
    }

    public function loggingEnabled(): bool
    {
        $value = $this->config->get('legacy-bridge.logging.enabled', true);

        return is_bool($value) ? $value : (bool) $value;
    }

    public function loggingChannel(): ?string
    {
        $value = $this->config->get('legacy-bridge.logging.channel');

        return is_string($value) ? $value : null;
    }

    public function shouldInvalidateAfterWrite(): bool
    {
        return $this->invalidation() === 'after_write';
    }

    private function string(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) ? $value : $default;
    }
}

<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge;

use Chr15k\LegacyBridge\Contracts\LegacyIntegration;
use Chr15k\LegacyBridge\Contracts\LegacyUserResolver;
use Chr15k\LegacyBridge\Integrations\Laravel;
use Illuminate\Support\Collection;

final readonly class Config
{
    /**
     * @param Collection<int, array{
     *     integration: class-string<LegacyIntegration>,
     *     cookie: string,
     *     connection: string,
     *     table: string,
     *     lifetime: int,
     *     format: string,
     *     legacy_app_key: string|null,
     *     cookie_encryption: string,
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
     * }> $config
     */
    public function __construct(private Collection $config)
    {
        //
    }

    public function integration(): string
    {
        return $this->string('legacy-bridge.integration', Laravel::class);
    }

    public function cookie(): string
    {
        return $this->string('legacy-bridge.cookie', 'PHPSESSID');
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
        $value = $this->config->get('lifetime', 120);

        return is_int($value) ? $value : (int) (is_string($value) ? $value : 120);
    }

    public function shouldDecryptLegacySession(): bool
    {
        return $this->format() === 'encrypted' && $this->legacyAppKey() !== null;
    }

    public function cookieEncryption(): string
    {
        return $this->string('legacy-bridge.cookie_encryption', 'none');
    }

    public function format(): string
    {
        return $this->string('legacy-bridge.format', 'auto');
    }

    public function legacyAppKey(): ?string
    {
        $value = $this->config->get('legacy_app_key');

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
        return $this->config->get('resolver');
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
        $value = $this->config->get('resolver.class');

        return is_string($value) ? $value : null; // @phpstan-ignore-line
    }

    /**
     * @return list<string>
     */
    public function contextCarryKeys(): array
    {
        /** @var list<string> */
        return $this->config->get('context.carry_keys', []);
    }

    public function contextFlash(): bool
    {
        $value = $this->config->get('context.flash', false);

        return is_bool($value) ? $value : (bool) $value;
    }

    public function loggingEnabled(): bool
    {
        $value = $this->config->get('logging.enabled', true);

        return is_bool($value) ? $value : (bool) $value;
    }

    public function loggingChannel(): ?string
    {
        $value = $this->config->get('logging.channel');

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

<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Integrations;

use Chr15k\LegacyBridge\Concerns\DecryptsLegacySessionData;
use Chr15k\LegacyBridge\Config;
use Chr15k\LegacyBridge\Contracts\LegacyIntegration;

final readonly class Laravel implements LegacyIntegration
{
    use DecryptsLegacySessionData;

    public function __construct(private Config $config) {}

    public function resolveSessionIdFromCookie(string|array|null $cookie): ?string
    {
        if (! is_string($cookie)) {
            return null;
        }

        // Allows the integration to be set (e.g. Laravel), while also giving
        // explicit control over whether the incoming cookie value is encrypted.
        if ($this->config->cookieEncryption() === 'laravel') {
            return $this->decrypt(payload: $cookie, unserialize: false, isCookie: true);
        }

        return $cookie;
    }
}

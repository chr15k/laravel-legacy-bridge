<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Cookie;

use Chr15k\LegacyBridge\Concerns\DecryptsLegacySessionData;
use Chr15k\LegacyBridge\Enums\CookieEncryption;
use Chr15k\LegacyBridge\Support\Config;
use Illuminate\Cookie\CookieValuePrefix;

final readonly class CookieValueResolver
{
    use DecryptsLegacySessionData;

    public function __construct(private Config $config) {}

    public function resolve(string $cookieValue): ?string
    {
        return match ($this->config->cookieEncryption()) {
            CookieEncryption::None    => $cookieValue,
            CookieEncryption::Laravel => $this->resolveLaravelCookie($cookieValue),
        };
    }

    private function resolveLaravelCookie(string $cookieValue): ?string
    {
        $decrypted = $this->decrypt($cookieValue, unserialize: false);

        $result = $decrypted === null ? null : CookieValuePrefix::remove($decrypted);

        if ($result === null || $result === '') {
            return null;
        }

        return $result;
    }
}

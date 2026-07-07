<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Session;

use Chr15k\LegacyBridge\Enums\CookieEncryption;
use Chr15k\LegacyBridge\Support\Config;
use Chr15k\LegacyBridge\Support\SessionDecrypter;
use Illuminate\Cookie\CookieValuePrefix;

final readonly class CookieValueResolver
{
    public function __construct(private Config $config, private SessionDecrypter $decrypter) {}

    public function resolve(string $cookieValue): ?string
    {
        return match ($this->config->cookieEncryption()) {
            CookieEncryption::None    => $cookieValue,
            CookieEncryption::Laravel => $this->resolveLaravelCookie($cookieValue),
        };
    }

    private function resolveLaravelCookie(string $cookieValue): ?string
    {
        $decrypted = $this->decrypter->decrypt($cookieValue, unserialize: false);

        $result = $decrypted === null ? null : CookieValuePrefix::remove($decrypted);

        if ($result === null || $result === '') {
            return null;
        }

        return $result;
    }
}

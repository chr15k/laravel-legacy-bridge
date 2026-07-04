<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Concerns;

use Chr15k\LegacyBridge\Config;
use Chr15k\LegacyBridge\Exceptions\MissingLegacyAppKeyException;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;

trait DecryptsLegacySessionData
{
    public function decryptCookieValue(string $payload, bool $unserialize = true): ?string
    {
        $decrypted = $this->decrypt($payload, $unserialize);

        $encryption = app(Config::class)->cookieEncryption();

        if ($encryption->isLaravel()) {
            return CookieValuePrefix::remove($decrypted);
        }

        return $decrypted;
    }

    public function decrypt(string $payload, bool $unserialize = true): ?string
    {
        $key = app(Config::class)->legacyAppKey();

        if (in_array($key, [null, '', '0'], true)) {
            throw new MissingLegacyAppKeyException;
        }

        $keyBytes = Str::startsWith($key, 'base64:')
            ? base64_decode(Str::after($key, 'base64:'))
            : $key;

        $cipher = Encrypter::supported($keyBytes, 'AES-256-CBC')
            ? 'AES-256-CBC'
            : 'AES-128-CBC';

        $encrypter = new Encrypter($keyBytes, $cipher);

        $decrypted = $encrypter->decrypt($payload, $unserialize);

        if (! is_string($decrypted)) {
            return null;
        }

        return $decrypted;
    }
}

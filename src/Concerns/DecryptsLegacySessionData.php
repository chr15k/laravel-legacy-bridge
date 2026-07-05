<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Concerns;

use Chr15k\LegacyBridge\Exceptions\MissingLegacyAppKeyException;
use Chr15k\LegacyBridge\Support\Config;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;

trait DecryptsLegacySessionData
{
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

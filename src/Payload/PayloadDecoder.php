<?php

namespace Chr15k\LegacyBridge\Payload;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use RuntimeException;

final class PayloadDecoder
{
    public function decode(string $raw, string $format = 'auto'): LegacyPayload
    {
        if ($format === 'auto') {
            $format = $this->detect($raw);
        }

        $data = match ($format) {
            'php_session' => $this->decodePhpSession($raw),
            'json'        => $this->decodeJson($raw),
            'laravel'     => $this->decodeLaravel($raw),
            'encrypted'   => $this->decodeEncrypted($raw),
            default       => [],
        };

        return new LegacyPayload($data, $format);
    }

    public function detect(string $raw): string
    {
        // PHP native session encoding: key|serialized_value;
        if (preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*\|/', $raw)) {
            return 'php_session';
        }

        // Attempt base64 decode — covers Laravel and base64-wrapped JSON
        $decoded = base64_decode($raw, strict: true);

        if ($decoded !== false) {
            // Laravel session: base64(serialize(array))
            $unserialized = @unserialize($decoded);
            if (is_array($unserialized)) {
                return 'laravel';
            }

            // base64-encoded JSON
            if (is_array(json_decode($decoded, true))) {
                return 'json';
            }
        }

        // Raw JSON
        if (is_array(json_decode($raw, true))) {
            return 'json';
        }

        return 'unknown';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePhpSession(string $raw): array
    {
        $segments = preg_split(
            '/([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\|/',
            $raw,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );

        if ($segments === false) {
            return [];
        }

        $vars = [];
        $counter = count($segments);

        for ($i = 1; $i < $counter; $i += 2) {
            $segment = $segments[$i + 1] ?? '';
            if ($segment === '') {
                continue;
            }

            if ($segment === '0') {
                continue;
            }

            $value = @unserialize($segment);

            if ($value !== false) {
                $vars[$segments[$i]] = $value;
            }
        }

        return $vars;
    }

    /**
     * @return array<mixed>
     */
    private function decodeJson(string $raw): array
    {
        // Try base64-encoded JSON first, then raw JSON
        $decoded = base64_decode($raw, strict: true);

        if ($decoded !== false) {
            $data = json_decode($decoded, true);
            if (is_array($data)) {
                return $data;
            }
        }

        $result = json_decode($raw, true);

        return is_array($result) ? $result : [];
    }

    /**
     * @return array<mixed>
     */
    private function decodeLaravel(string $raw): array
    {
        $decoded = base64_decode($raw, strict: true);

        if ($decoded === false) {
            return [];
        }

        $data = @unserialize($decoded);

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<mixed>
     */
    private function decodeEncrypted(string $raw): array
    {
        $key = config('legacy-bridge.legacy_app_key');

        if (! $key || ! is_string($key)) {
            throw new RuntimeException(
                'legacy-bridge: format is "encrypted" but legacy_app_key is not set in config/legacy-bridge.php'
            );
        }

        $keyBytes = Str::startsWith($key, 'base64:')
            ? base64_decode(Str::after($key, 'base64:'))
            : $key;

        $encrypter = new Encrypter($keyBytes, 'AES-256-CBC');

        $decrypted = $encrypter->decrypt($raw);

        if (is_array($decrypted)) {
            return $decrypted;
        }

        if (! is_string($decrypted)) {
            return [];
        }

        $data = @unserialize($decrypted);

        return is_array($data) ? $data : [];
    }
}

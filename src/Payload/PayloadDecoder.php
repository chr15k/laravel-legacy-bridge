<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Payload;

use Chr15k\LegacyBridge\Concerns\DecryptsLegacySessionData;
use Chr15k\LegacyBridge\Config;

final class PayloadDecoder
{
    public function __construct(private Config $config)
    {
        //
    }

    use DecryptsLegacySessionData;

    public function decode(string $raw, string $format = 'auto'): LegacyPayload
    {
        if ($format === 'auto') {
            $format = $this->detect($raw);
        }

        $data = match ($format) {
            'php_session' => $this->decodePhpSession($raw),
            'json'        => $this->decodeJson($raw),
            'laravel'     => $this->decodeLaravel($raw),
            'encrypted'   => $this->decrypt(payload: $raw),
            default       => [],
        };

        return new LegacyPayload($data, $format);
    }

    public function detect(string $raw): string
    {
        if (preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*\|/', $raw)) {
            return 'php_session';
        }

        $decoded = base64_decode($raw, strict: true);

        if ($decoded !== false) {
            $unserialized = @unserialize($decoded);
            if (is_array($unserialized)) {
                return 'laravel'; // genuinely unserializes cleanly
            }

            if (is_array(json_decode($decoded, true))) {
                return 'json';
            }
        }

        if (is_array(json_decode($raw, true))) {
            return 'json';
        }

        // Doesn't match any known plain format — likely encrypted, but
        // auto-detection can't confirm this without the legacy_app_key.
        // Surface this clearly rather than silently returning 'unknown'.
        return $this->config->legacyAppKey() ? 'encrypted' : 'unknown';
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

        if (json_validate($decoded)) {
            return json_decode($decoded, true) ?: [];
        }

        $data = unserialize($decoded, ['allowed_classes' => false]);

        return is_array($data) ? $data : [];
    }
}

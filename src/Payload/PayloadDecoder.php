<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Payload;

use Chr15k\LegacyBridge\Concerns\DecryptsLegacySessionData;
use Chr15k\LegacyBridge\Enums\PayloadFormat;
use Chr15k\LegacyBridge\Support\Config;
use RuntimeException;

final class PayloadDecoder
{
    use DecryptsLegacySessionData;

    public function decode(string $raw, PayloadFormat $config): LegacyPayload
    {
        $format = $config === PayloadFormat::Auto ? $this->detect($raw) : $config;

        $data = match ($format) {
            PayloadFormat::PhpSession => $this->decodePhpSession($raw),
            PayloadFormat::Json       => $this->decodeJson($raw),
            PayloadFormat::Laravel    => $this->decodeLaravel($raw),
            PayloadFormat::Encrypted  => $this->decodeEncrypted($raw),
            default                   => [],
        };

        return new LegacyPayload($data);
    }

    public function detect(string $raw): ?PayloadFormat
    {
        if (preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*\|/', $raw)) {
            return PayloadFormat::PhpSession;
        }

        $decoded = base64_decode($raw, strict: true);

        if ($decoded !== false) {
            $unserialized = @unserialize($decoded, ['allowed_classes' => false]);

            if (is_array($unserialized)) {
                return PayloadFormat::Laravel;
            }

            if (is_array(json_decode($decoded, true))) {
                return PayloadFormat::Json;
            }
        }

        if (is_array(json_decode($raw, true))) {
            return PayloadFormat::Json;
        }

        if (! app(Config::class)->legacyAppKey()) {
            return null;
        }

        try {
            return $this->decrypt(payload: $raw) ? PayloadFormat::Encrypted : null;
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * @return array<mixed>
     */
    private function decodeEncrypted(string $raw): array
    {
        $decrypted = $this->decrypt(payload: $raw);

        if ($decrypted === null) {
            return [];
        }

        $format = $this->detect($decrypted) ?? PayloadFormat::PhpSession;

        return match ($format) {
            PayloadFormat::PhpSession => $this->decodePhpSession($decrypted),
            PayloadFormat::Json       => $this->decodeJson($decrypted),
            PayloadFormat::Laravel    => $this->decodeLaravel($decrypted),
            default                   => [],
        };
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

            $value = @unserialize($segment, ['allowed_classes' => false]);

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

        $data = (json_validate($decoded))
            ? json_decode($decoded, true)
            : @unserialize($decoded, ['allowed_classes' => false]);

        return is_array($data) ? $data : [];
    }
}

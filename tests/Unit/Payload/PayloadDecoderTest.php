<?php

declare(strict_types=1);

use Chr15k\LegacyBridge\Enums\PayloadFormat;
use Chr15k\LegacyBridge\Payload\LegacyPayload;
use Chr15k\LegacyBridge\Payload\PayloadDecoder;

beforeEach(function (): void {
    $this->decoder = new PayloadDecoder;
});

// ---------------------------------------------------------------------------
// Format detection
// ---------------------------------------------------------------------------

describe('format detection', function (): void {
    it('detects php_session format', function (): void {
        $raw = 'user_id|i:42;username|s:4:"john";';
        expect($this->decoder->detect($raw))->toBe(PayloadFormat::PhpSession);
    });

    it('detects laravel format', function (): void {
        $data = base64_encode(serialize(['user_id' => 42, '_token' => 'abc']));
        expect($this->decoder->detect($data))->toBe(PayloadFormat::Laravel);
    });

    it('detects base64 json format', function (): void {
        $data = base64_encode(json_encode(['user_id' => 42]));
        expect($this->decoder->detect($data))->toBe(PayloadFormat::Json);
    });

    it('detects raw json format', function (): void {
        $data = json_encode(['user_id' => 42]);
        expect($this->decoder->detect($data))->toBe(PayloadFormat::Json);
    });

    it('returns null for unrecognised payloads without an app key', function (): void {
        expect($this->decoder->detect('not_valid_at_all####'))->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// php_session decoding
// ---------------------------------------------------------------------------

describe('php_session decoding', function (): void {
    it('decodes a flat php session', function (): void {
        $raw = 'user_id|i:42;username|s:4:"john";';
        $payload = $this->decoder->decode($raw, PayloadFormat::PhpSession);

        expect($payload)->toBeInstanceOf(LegacyPayload::class)
            ->and($payload->get('user_id'))->toBe(42)
            ->and($payload->get('username'))->toBe('john');
    });

    it('decodes a nested php session with serialized object', function (): void {
        $user = new stdClass;
        $user->id = 99;
        $user->email = 'test@example.com';

        $raw = 'user|'.serialize($user).';';
        $payload = $this->decoder->decode($raw, PayloadFormat::PhpSession);

        expect($payload->get('user'))->toBeObject()
            ->and($payload->get('user')->id)->toBe(99);
    });

    it('returns empty payload for malformed php session', function (): void {
        $payload = $this->decoder->decode('not|a|valid|session', PayloadFormat::PhpSession);
        expect($payload->isEmpty())->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// Laravel format decoding
// ---------------------------------------------------------------------------

describe('laravel format decoding', function (): void {
    it('decodes a laravel serialized session payload', function (): void {
        $data = ['user_id' => 7, '_token' => 'csrf-token-value'];
        $raw = base64_encode(serialize($data));
        $payload = $this->decoder->decode($raw, PayloadFormat::Laravel);

        expect($payload->get('user_id'))->toBe(7)
            ->and($payload->get('_token'))->toBe('csrf-token-value');
    });

    it('decodes a laravel json session payload', function (): void {
        $data = ['user_id' => 7, '_token' => 'csrf-token-value'];
        $raw = base64_encode(json_encode($data));
        $payload = $this->decoder->decode($raw, PayloadFormat::Laravel);

        expect($payload->get('user_id'))->toBe(7);
    });

    it('returns empty payload for invalid base64', function (): void {
        $payload = $this->decoder->decode('!!!not-base64!!!', PayloadFormat::Laravel);
        expect($payload->isEmpty())->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// JSON format decoding
// ---------------------------------------------------------------------------

describe('json format decoding', function (): void {
    it('decodes raw json', function (): void {
        $raw = json_encode(['user_id' => 5, 'locale' => 'en']);
        $payload = $this->decoder->decode($raw, PayloadFormat::Json);

        expect($payload->get('user_id'))->toBe(5)
            ->and($payload->get('locale'))->toBe('en');
    });

    it('decodes base64 encoded json', function (): void {
        $raw = base64_encode(json_encode(['user_id' => 5]));
        $payload = $this->decoder->decode($raw, PayloadFormat::Json);

        expect($payload->get('user_id'))->toBe(5);
    });

    it('returns empty payload for invalid json', function (): void {
        $payload = $this->decoder->decode('not-json-at-all', PayloadFormat::Json);
        expect($payload->isEmpty())->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// Auto detection
// ---------------------------------------------------------------------------

describe('auto detection', function (): void {
    it('auto-detects and decodes laravel format', function (): void {
        $raw = base64_encode(serialize(['user_id' => 42]));
        $payload = $this->decoder->decode($raw, PayloadFormat::Auto);

        expect($payload->get('user_id'))->toBe(42);
    });
});

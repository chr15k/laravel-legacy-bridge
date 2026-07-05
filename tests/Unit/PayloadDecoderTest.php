<?php

use Chr15k\LegacyBridge\Enums\PayloadFormat;
use Chr15k\LegacyBridge\Payload\LegacyPayload;
use Chr15k\LegacyBridge\Payload\PayloadDecoder;

beforeEach(function (): void {
    $this->decoder = app(PayloadDecoder::class);
});

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

    it('returns unknown for unrecognised payloads', function (): void {
        expect($this->decoder->detect('not_valid_at_all####'))->toBeNull();
    });
});

describe('php_session decoding', function (): void {
    it('decodes a flat php session', function (): void {
        $raw = 'user_id|i:42;username|s:4:"john";';
        $payload = $this->decoder->decode($raw, PayloadFormat::PhpSession);

        expect($payload)->toBeInstanceOf(LegacyPayload::class)
            ->and($payload->get('user_id'))->toBe(42)
            ->and($payload->get('username'))->toBe('john')
            ->and($payload->format())->toBe('php_session');
    });

    it('decodes a nested php session object', function (): void {
        $user = new stdClass;
        $user->id = 99;
        $user->email = 'test@example.com';

        $raw = 'user|'.serialize($user).';';
        $payload = $this->decoder->decode($raw, PayloadFormat::PhpSession);

        expect($payload->get('user')->id)->toBe(99);
    });
});

describe('laravel format decoding', function (): void {
    it('decodes a laravel session payload', function (): void {
        $data = ['user_id' => 7, '_token' => 'csrf-token-value'];
        $raw = base64_encode(serialize($data));
        $payload = $this->decoder->decode($raw, PayloadFormat::Laravel);

        expect($payload->get('user_id'))->toBe(7)
            ->and($payload->get('_token'))->toBe('csrf-token-value');
    });
});

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
});

describe('auto detection', function (): void {
    it('auto-detects and decodes php_session format', function (): void {
        $raw = 'user_id|i:88;';
        $payload = $this->decoder->decode($raw, PayloadFormat::Auto);

        expect($payload->get('user_id'))->toBe(88)
            ->and($payload->format())->toBe('php_session');
    });
});

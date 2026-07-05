<?php

declare(strict_types=1);

use Chr15k\LegacyBridge\Enums\PayloadFormat;
use Chr15k\LegacyBridge\Payload\LegacyPayload;

// ---------------------------------------------------------------------------
// Basic access
// ---------------------------------------------------------------------------

describe('LegacyPayload', function (): void {
    it('accesses top-level keys', function (): void {
        $payload = new LegacyPayload(['user_id' => 42], PayloadFormat::Json);
        expect($payload->get('user_id'))->toBe(42);
    });

    it('supports dot-notation for nested keys', function (): void {
        $payload = new LegacyPayload(['auth' => ['user' => ['id' => 7]]], PayloadFormat::Json);
        expect($payload->get('auth.user.id'))->toBe(7);
    });

    it('returns the default when a key is missing', function (): void {
        $payload = new LegacyPayload([], PayloadFormat::Json);
        expect($payload->get('missing', 'fallback'))->toBe('fallback');
    });

    it('checks key existence with has()', function (): void {
        $payload = new LegacyPayload(['user_id' => 1], PayloadFormat::Json);
        expect($payload->has('user_id'))->toBeTrue()
            ->and($payload->has('other'))->toBeFalse();
    });

    it('returns false for has() when value is null', function (): void {
        $payload = new LegacyPayload(['key' => null], PayloadFormat::Json);
        expect($payload->has('key'))->toBeFalse();
    });

    it('reports isEmpty correctly', function (): void {
        expect((new LegacyPayload([], PayloadFormat::Json))->isEmpty())->toBeTrue()
            ->and((new LegacyPayload(['a' => 1], PayloadFormat::Json))->isEmpty())->toBeFalse();
    });

    it('returns only specified keys', function (): void {
        $payload = new LegacyPayload(['user_id' => 1, 'locale' => 'en', 'cart_id' => 9], PayloadFormat::Json);
        expect($payload->only(['locale', 'cart_id']))->toBe(['locale' => 'en', 'cart_id' => 9]);
    });

    it('returns all data', function (): void {
        $data = ['user_id' => 1, 'locale' => 'en'];
        $payload = new LegacyPayload($data, PayloadFormat::Json);
        expect($payload->all())->toBe($data);
    });
});

// ---------------------------------------------------------------------------
// resolveId
// ---------------------------------------------------------------------------

describe('resolveId()', function (): void {
    it('resolves a scalar integer', function (): void {
        $payload = new LegacyPayload(['user_id' => 42], PayloadFormat::Json);
        expect($payload->resolveId('user_id'))->toBe(42);
    });

    it('resolves a string integer', function (): void {
        $payload = new LegacyPayload(['user_id' => '42'], PayloadFormat::Json);
        expect($payload->resolveId('user_id'))->toBe(42);
    });

    it('resolves an object with an id property', function (): void {
        $user = new stdClass;
        $user->id = 99;

        $payload = new LegacyPayload(['user' => $user], PayloadFormat::PhpSession);

        expect($payload->resolveId('user'))->toBe(99);
    });

    it('resolves an array with an id key', function (): void {
        $payload = new LegacyPayload(['user' => ['id' => 55]], PayloadFormat::Json);
        expect($payload->resolveId('user'))->toBe(55);
    });

    it('resolves a nested path', function (): void {
        $payload = new LegacyPayload(['auth' => ['user' => ['id' => 7]]], PayloadFormat::Json);
        expect($payload->resolveId('auth.user.id'))->toBe(7);
    });

    it('returns null for a missing path', function (): void {
        $payload = new LegacyPayload([], PayloadFormat::Json);
        expect($payload->resolveId('user_id'))->toBeNull();
    });

    it('returns null for a zero string (invalid ID)', function (): void {
        $payload = new LegacyPayload(['user_id' => '0'], PayloadFormat::Json);
        expect($payload->resolveId('user_id'))->toBeNull();
    });

    it('returns null for zero integer', function (): void {
        $payload = new LegacyPayload(['user_id' => 0], PayloadFormat::Json);
        expect($payload->resolveId('user_id'))->toBeNull();
    });

    it('returns null when value is null', function (): void {
        $payload = new LegacyPayload(['user_id' => null], PayloadFormat::Json);
        expect($payload->resolveId('user_id'))->toBeNull();
    });
});

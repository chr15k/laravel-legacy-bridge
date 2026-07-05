<?php

declare(strict_types=1);

use Chr15k\LegacyBridge\Enums\PayloadFormat;
use Chr15k\LegacyBridge\Payload\LegacyPayload;
use Chr15k\LegacyBridge\Resolvers\AutoResolver;
use Chr15k\LegacyBridge\Resolvers\KeyResolver;

// ---------------------------------------------------------------------------
// KeyResolver
// ---------------------------------------------------------------------------

describe('KeyResolver', function (): void {
    it('resolves a top-level user_id', function (): void {
        $resolver = new KeyResolver('user_id');
        $payload = new LegacyPayload(['user_id' => 42], PayloadFormat::Json);

        expect($resolver->resolve($payload))->toBe(42);
    });

    it('resolves a nested path', function (): void {
        $resolver = new KeyResolver('auth.user.id');
        $payload = new LegacyPayload(['auth' => ['user' => ['id' => 7]]], PayloadFormat::Json);

        expect($resolver->resolve($payload))->toBe(7);
    });

    it('returns null when key is missing', function (): void {
        $resolver = new KeyResolver('user_id');
        $payload = new LegacyPayload(['other_key' => 1], PayloadFormat::Json);

        expect($resolver->resolve($payload))->toBeNull();
    });

    it('uses user_id as default key', function (): void {
        $resolver = new KeyResolver;
        $payload = new LegacyPayload(['user_id' => 5], PayloadFormat::Json);

        expect($resolver->resolve($payload))->toBe(5);
    });
});

// ---------------------------------------------------------------------------
// AutoResolver
// ---------------------------------------------------------------------------

describe('AutoResolver', function (): void {
    it('resolves a flat user_id', function (): void {
        $resolver = new AutoResolver;
        $payload = new LegacyPayload(['user_id' => 42], PayloadFormat::Json);

        expect($resolver->resolve($payload))->toBe(42);
    });

    it('resolves userId camelCase', function (): void {
        $resolver = new AutoResolver;
        $payload = new LegacyPayload(['userId' => 42], PayloadFormat::Json);

        expect($resolver->resolve($payload))->toBe(42);
    });

    it('resolves a laravel login_ prefixed key', function (): void {
        $resolver = new AutoResolver;
        $payload = new LegacyPayload(['login_web_59ba36addc2b2f9401580f014c7f58ea4e30989d' => 7], PayloadFormat::Laravel);

        expect($resolver->resolve($payload))->toBe(7);
    });

    it('resolves auth.user.id nested path', function (): void {
        $resolver = new AutoResolver;
        $payload = new LegacyPayload(['auth' => ['user' => ['id' => 99]]], PayloadFormat::Json);

        expect($resolver->resolve($payload))->toBe(99);
    });

    it('resolves auth.id nested path', function (): void {
        $resolver = new AutoResolver;
        $payload = new LegacyPayload(['auth' => ['id' => 15]], PayloadFormat::Json);

        expect($resolver->resolve($payload))->toBe(15);
    });

    it('resolves user.id nested path', function (): void {
        $resolver = new AutoResolver;
        $payload = new LegacyPayload(['user' => ['id' => 22]], PayloadFormat::Json);

        expect($resolver->resolve($payload))->toBe(22);
    });

    it('returns null when no known pattern matches', function (): void {
        $resolver = new AutoResolver;
        $payload = new LegacyPayload(['some_unrelated_key' => 'value'], PayloadFormat::Json);

        expect($resolver->resolve($payload))->toBeNull();
    });

    it('returns null for empty payload', function (): void {
        $resolver = new AutoResolver;
        $payload = new LegacyPayload([], PayloadFormat::Json);

        expect($resolver->resolve($payload))->toBeNull();
    });

    it('resolves sentinel pattern', function (): void {
        $resolver = new AutoResolver;
        $payload = new LegacyPayload(['cartalyst_sentinel' => ['id' => 13]], PayloadFormat::Json);

        expect($resolver->resolve($payload))->toBe(13);
    });
});

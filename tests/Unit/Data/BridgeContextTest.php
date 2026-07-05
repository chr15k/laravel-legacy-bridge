<?php

declare(strict_types=1);

use Chr15k\LegacyBridge\Data\BridgeContext;
use Chr15k\LegacyBridge\Enums\PayloadFormat;
use Chr15k\LegacyBridge\Payload\LegacyPayload;

describe('BridgeContext', function (): void {
    it('constructs with required fields', function (): void {
        $ctx = new BridgeContext(
            cookieName: 'PHPSESSID',
            requestContext: ['ip' => '127.0.0.1'],
        );

        expect($ctx->cookieName)->toBe('PHPSESSID')
            ->and($ctx->requestContext)->toBe(['ip' => '127.0.0.1'])
            ->and($ctx->cookieValue)->toBeNull()
            ->and($ctx->sessionId)->toBeNull()
            ->and($ctx->payload)->toBeNull()
            ->and($ctx->userId)->toBeNull();
    });

    it('constructs with cookie value', function (): void {
        $ctx = new BridgeContext(
            cookieName: 'PHPSESSID',
            requestContext: [],
            cookieValue: 'abc123',
        );

        expect($ctx->cookieValue)->toBe('abc123');
    });

    it('withSessionId returns a new immutable instance', function (): void {
        $ctx     = new BridgeContext(cookieName: 'PHPSESSID', requestContext: []);
        $updated = $ctx->withSessionId('session-123');

        expect($updated->sessionId)->toBe('session-123')
            ->and($ctx->sessionId)->toBeNull() // original unchanged
            ->and($updated)->not->toBe($ctx);
    });

    it('withPayload returns a new immutable instance', function (): void {
        $ctx     = new BridgeContext(cookieName: 'PHPSESSID', requestContext: []);
        $payload = new LegacyPayload(['user_id' => 1], PayloadFormat::Json);
        $updated = $ctx->withPayload($payload);

        expect($updated->payload)->toBe($payload)
            ->and($ctx->payload)->toBeNull()
            ->and($updated)->not->toBe($ctx);
    });

    it('withUserId returns a new immutable instance', function (): void {
        $ctx     = new BridgeContext(cookieName: 'PHPSESSID', requestContext: []);
        $updated = $ctx->withUserId(42);

        expect($updated->userId)->toBe(42)
            ->and($ctx->userId)->toBeNull()
            ->and($updated)->not->toBe($ctx);
    });

    it('preserves all existing fields when creating new instances', function (): void {
        $payload = new LegacyPayload(['user_id' => 1], PayloadFormat::Json);

        $ctx = new BridgeContext(
            cookieName: 'PHPSESSID',
            requestContext: ['ip' => '127.0.0.1'],
            cookieValue: 'abc123',
        );

        $updated = $ctx
            ->withSessionId('session-123')
            ->withPayload($payload)
            ->withUserId(42);

        expect($updated->cookieName)->toBe('PHPSESSID')
            ->and($updated->requestContext)->toBe(['ip' => '127.0.0.1'])
            ->and($updated->cookieValue)->toBe('abc123')
            ->and($updated->sessionId)->toBe('session-123')
            ->and($updated->payload)->toBe($payload)
            ->and($updated->userId)->toBe(42);
    });
});
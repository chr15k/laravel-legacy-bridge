<?php

declare(strict_types=1);

use Chr15k\LegacyBridge\Data\LegacySession;

describe('LegacySession', function (): void {
    it('constructs with all fields', function (): void {
        $session = new LegacySession(
            id: 'abc123',
            userId: 1,
            ipAddress: '127.0.0.1',
            userAgent: 'Mozilla/5.0',
            payload: 'encoded-payload',
            lastActivity: 1700000000,
            expired: false,
            age: 5.0,
        );

        expect($session->id)->toBe('abc123')
            ->and($session->userId)->toBe(1)
            ->and($session->ipAddress)->toBe('127.0.0.1')
            ->and($session->userAgent)->toBe('Mozilla/5.0')
            ->and($session->payload)->toBe('encoded-payload')
            ->and($session->lastActivity)->toBe(1700000000)
            ->and($session->expired)->toBeFalse()
            ->and($session->age)->toBe(5.0);
    });

    it('constructs with nullable fields', function (): void {
        $session = new LegacySession(
            id: 'abc123',
            userId: null,
            ipAddress: null,
            userAgent: null,
            payload: 'encoded-payload',
            lastActivity: 1700000000,
            expired: false,
            age: 0.0,
        );

        expect($session->userId)->toBeNull()
            ->and($session->ipAddress)->toBeNull()
            ->and($session->userAgent)->toBeNull();
    });

    it('identifies an expired session', function (): void {
        $session = new LegacySession(
            id: 'abc123',
            userId: 1,
            ipAddress: null,
            userAgent: null,
            payload: '',
            lastActivity: now()->subHours(5)->timestamp,
            expired: true,
            age: 300.0,
        );

        expect($session->expired)->toBeTrue()
            ->and($session->age)->toBe(300.0);
    });

    it('accepts string user IDs', function (): void {
        $session = new LegacySession(
            id: 'abc123',
            userId: 'uuid-style-id',
            ipAddress: null,
            userAgent: null,
            payload: '',
            lastActivity: now()->timestamp,
            expired: false,
            age: 0.0,
        );

        expect($session->userId)->toBe('uuid-style-id');
    });
});
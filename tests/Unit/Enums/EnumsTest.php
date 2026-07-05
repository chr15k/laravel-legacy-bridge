<?php

declare(strict_types=1);

use Carbon\Carbon;
use Chr15k\LegacyBridge\Enums\BridgeFailureReason;
use Chr15k\LegacyBridge\Enums\CookieEncryption;
use Chr15k\LegacyBridge\Enums\InvalidationStrategy;
use Chr15k\LegacyBridge\Enums\PayloadFormat;
use Chr15k\LegacyBridge\Enums\SessionTimeFormat;
use Chr15k\LegacyBridge\Enums\SessionTimeSemantics;

// ---------------------------------------------------------------------------
// CookieEncryption
// ---------------------------------------------------------------------------

describe('CookieEncryption', function (): void {
    it('identifies laravel encryption', function (): void {
        expect(CookieEncryption::Laravel->isLaravel())->toBeTrue()
            ->and(CookieEncryption::None->isLaravel())->toBeFalse();
    });

    it('casts from string', function (): void {
        expect(CookieEncryption::tryFrom('laravel'))->toBe(CookieEncryption::Laravel)
            ->and(CookieEncryption::tryFrom('none'))->toBe(CookieEncryption::None)
            ->and(CookieEncryption::tryFrom('invalid'))->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// InvalidationStrategy
// ---------------------------------------------------------------------------

describe('InvalidationStrategy', function (): void {
    it('identifies immediate strategy', function (): void {
        expect(InvalidationStrategy::Immediate->isImmediate())->toBeTrue()
            ->and(InvalidationStrategy::AfterWrite->isImmediate())->toBeFalse()
            ->and(InvalidationStrategy::Never->isImmediate())->toBeFalse();
    });

    it('identifies after_write strategy', function (): void {
        expect(InvalidationStrategy::AfterWrite->isAfterWrite())->toBeTrue()
            ->and(InvalidationStrategy::Immediate->isAfterWrite())->toBeFalse()
            ->and(InvalidationStrategy::Never->isAfterWrite())->toBeFalse();
    });

    it('casts from string', function (): void {
        expect(InvalidationStrategy::tryFrom('after_write'))->toBe(InvalidationStrategy::AfterWrite)
            ->and(InvalidationStrategy::tryFrom('immediate'))->toBe(InvalidationStrategy::Immediate)
            ->and(InvalidationStrategy::tryFrom('never'))->toBe(InvalidationStrategy::Never);
    });
});

// ---------------------------------------------------------------------------
// SessionTimeFormat
// ---------------------------------------------------------------------------

describe('SessionTimeFormat', function (): void {
    it('identifies datetime format', function (): void {
        expect(SessionTimeFormat::Datetime->isDatetime())->toBeTrue()
            ->and(SessionTimeFormat::Timestamp->isDatetime())->toBeFalse();
    });

    it('identifies timestamp format', function (): void {
        expect(SessionTimeFormat::Timestamp->isTimestamp())->toBeTrue()
            ->and(SessionTimeFormat::Datetime->isTimestamp())->toBeFalse();
    });

    it('converts carbon to timestamp storage', function (): void {
        $carbon = Carbon::createFromTimestamp(1700000000);
        expect(SessionTimeFormat::Timestamp->toStorage($carbon))->toBe(1700000000);
    });

    it('converts carbon to datetime storage', function (): void {
        $carbon = Carbon::createFromTimestamp(1700000000);
        expect(SessionTimeFormat::Datetime->toStorage($carbon))->toBeString();
    });

    it('parses datetime from storage', function (): void {
        $carbon = SessionTimeFormat::Datetime->fromStorage('2024-01-01 00:00:00');
        expect($carbon)->toBeInstanceOf(\Carbon\CarbonInterface::class);
    });

    it('parses timestamp from storage', function (): void {
        $carbon = SessionTimeFormat::Timestamp->fromStorage(1700000000);
        expect($carbon->timestamp)->toBe(1700000000);
    });
});

// ---------------------------------------------------------------------------
// SessionTimeSemantics
// ---------------------------------------------------------------------------

describe('SessionTimeSemantics', function (): void {
    it('identifies expires semantics', function (): void {
        expect(SessionTimeSemantics::Expires->representsExpires())->toBeTrue()
            ->and(SessionTimeSemantics::Activity->representsExpires())->toBeFalse();
    });

    it('identifies activity semantics', function (): void {
        expect(SessionTimeSemantics::Activity->representsActivity())->toBeTrue()
            ->and(SessionTimeSemantics::Expires->representsActivity())->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// BridgeFailureReason
// ---------------------------------------------------------------------------

describe('BridgeFailureReason', function (): void {
    it('has expected string values', function (): void {
        expect(BridgeFailureReason::MissingCookie->value)->toBe('missing_cookie')
            ->and(BridgeFailureReason::InvalidCookie->value)->toBe('invalid_cookie')
            ->and(BridgeFailureReason::SessionNotFound->value)->toBe('session_not_found')
            ->and(BridgeFailureReason::SessionExpired->value)->toBe('session_expired')
            ->and(BridgeFailureReason::PayloadDecodeFailed->value)->toBe('payload_decode_failed')
            ->and(BridgeFailureReason::UserNotResolved->value)->toBe('user_not_resolved')
            ->and(BridgeFailureReason::AuthenticationFailed->value)->toBe('authentication_failed');
    });
});

// ---------------------------------------------------------------------------
// PayloadFormat
// ---------------------------------------------------------------------------

describe('PayloadFormat', function (): void {
    it('has expected string values', function (): void {
        expect(PayloadFormat::Auto->value)->toBe('auto')
            ->and(PayloadFormat::PhpSession->value)->toBe('php_session')
            ->and(PayloadFormat::Json->value)->toBe('json')
            ->and(PayloadFormat::Laravel->value)->toBe('laravel')
            ->and(PayloadFormat::Encrypted->value)->toBe('encrypted');
    });

    it('casts from string', function (): void {
        expect(PayloadFormat::tryFrom('auto'))->toBe(PayloadFormat::Auto)
            ->and(PayloadFormat::tryFrom('php_session'))->toBe(PayloadFormat::PhpSession)
            ->and(PayloadFormat::tryFrom('invalid'))->toBeNull();
    });
});
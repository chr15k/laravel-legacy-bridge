<?php

declare(strict_types=1);

use Chr15k\LegacyBridge\Enums\InvalidationStrategy;
use Chr15k\LegacyBridge\Events\LegacySessionBridgeError;
use Chr15k\LegacyBridge\Events\LegacySessionBridgeFailed;
use Chr15k\LegacyBridge\Events\LegacySessionBridged;
use Chr15k\LegacyBridge\Enums\BridgeFailureReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Workbench\App\Models\User;

beforeEach(function (): void {
    Event::fake();
});

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------

describe('authentication', function (): void {
    it('bridges a valid legacy session and authenticates the user', function (): void {
        $user = User::factory()->create();

        legacySession(['user_id' => $user->id]);

        $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
            ->get('/protected')
            ->assertOk();

        $this->assertAuthenticated();

        Event::assertDispatched(LegacySessionBridged::class, function ($event) use ($user) {
            return $event->userId === $user->id && $event->sessionId === 'test-session';
        });
    });

    it('authenticates the correct user from the legacy session', function (): void {
        User::factory()->create();
        $userB = User::factory()->create();

        legacySession(['user_id' => $userB->id]);

        $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
            ->get('/protected')
            ->assertOk();

        expect(\Illuminate\Support\Facades\Auth::id())->toBe($userB->id);
    });

    it('skips bridge when user is already authenticated', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/protected')
            ->assertOk();

        Event::assertNotDispatched(LegacySessionBridged::class);
        Event::assertNotDispatched(LegacySessionBridgeFailed::class);
    });

    it('handles multiple independent sessions for different users', function (): void {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        legacySession(['user_id' => $userA->id], 'session-a');
        legacySession(['user_id' => $userB->id], 'session-b');

        $this->withUnencryptedCookies(['PHPSESSID' => 'session-a'])
            ->get('/protected')
            ->assertOk();

        expect(\Illuminate\Support\Facades\Auth::id())->toBe($userA->id);

        \Illuminate\Support\Facades\Auth::logout();

        $this->withUnencryptedCookies(['PHPSESSID' => 'session-b'])
            ->get('/protected')
            ->assertOk();

        expect(\Illuminate\Support\Facades\Auth::id())->toBe($userB->id);
    });
});

// ---------------------------------------------------------------------------
// Failure reasons + events
// ---------------------------------------------------------------------------

describe('failure events', function (): void {
    it('dispatches MissingCookie when no legacy cookie is present', function (): void {
        $this->get('/protected')->assertRedirect('/login');

        Event::assertDispatched(LegacySessionBridgeFailed::class, function ($event) {
            return $event->reason === BridgeFailureReason::MissingCookie;
        });

        $this->assertGuest();
    });

    it('dispatches SessionNotFound when cookie does not match any session', function (): void {
        $this->withUnencryptedCookies(['PHPSESSID' => 'non-existent-session'])
            ->get('/protected')
            ->assertRedirect('/login');

        Event::assertDispatched(LegacySessionBridgeFailed::class, function ($event) {
            return $event->reason === BridgeFailureReason::SessionNotFound;
        });

        $this->assertGuest();
    });

    it('dispatches SessionNotFound when session has expired', function (): void {
        $user = User::factory()->create();

        legacySession(
            payload: ['user_id' => $user->id],
            lastActivity: now()->subMinutes(200)->timestamp,
        );

        $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
            ->get('/protected')
            ->assertRedirect('/login');

        Event::assertDispatched(LegacySessionBridgeFailed::class, function ($event) {
            return $event->reason === BridgeFailureReason::SessionNotFound;
        });

        $this->assertGuest();
    });

    it('dispatches PayloadDecodeFailed when payload is empty', function (): void {
        DB::table('legacy_sessions')->insert([
            'id'            => 'test-session',
            'payload'       => base64_encode(serialize([])),
            'last_activity' => now()->timestamp,
        ]);

        $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
            ->get('/protected')
            ->assertRedirect('/login');

        Event::assertDispatched(LegacySessionBridgeFailed::class, function ($event) {
            return $event->reason === BridgeFailureReason::PayloadDecodeFailed;
        });
    });

    it('dispatches UserNotResolved when payload has no user ID', function (): void {
        legacySession(['some_other_key' => 'value']);

        $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
            ->get('/protected')
            ->assertRedirect('/login');

        Event::assertDispatched(LegacySessionBridgeFailed::class, function ($event) {
            return $event->reason === BridgeFailureReason::UserNotResolved;
        });
    });

    it('dispatches AuthenticationFailed when user ID does not exist in users table', function (): void {
        legacySession(['user_id' => 99999]);

        $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
            ->get('/protected')
            ->assertRedirect('/login');

        Event::assertDispatched(LegacySessionBridgeFailed::class, function ($event) {
            return $event->reason === BridgeFailureReason::AuthenticationFailed;
        });
    });

    it('dispatches BridgeError on unexpected exception and does not break the request', function (): void {
        config()->set('legacy-bridge.database.connection', 'nonexistent_connection');

        $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
            ->get('/protected')
            ->assertRedirect('/login');

        Event::assertDispatched(LegacySessionBridgeError::class);
    });

    it('includes context in the failed event', function (): void {
        $this->withUnencryptedCookies(['PHPSESSID' => 'ghost-session'])
            ->get('/protected');

        Event::assertDispatched(LegacySessionBridgeFailed::class, function ($event) {
            return $event->context->cookieName === 'PHPSESSID'
                && $event->context->cookieValue === 'ghost-session';
        });
    });
});

// ---------------------------------------------------------------------------
// Payload formats
// ---------------------------------------------------------------------------

describe('payload formats', function (): void {
    it('bridges a laravel format payload', function (): void {
        $user = User::factory()->create();

        DB::table('legacy_sessions')->insert([
            'id'            => 'test-session',
            'payload'       => base64_encode(serialize(['user_id' => $user->id])),
            'last_activity' => now()->timestamp,
        ]);

        $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
            ->get('/protected')
            ->assertOk();

        $this->assertAuthenticated();
    });

    it('bridges a native php session format payload', function (): void {
        $user = User::factory()->create();

        DB::table('legacy_sessions')->insert([
            'id'            => 'test-session',
            'payload'       => phpSessionPayload(['user_id' => $user->id]),
            'last_activity' => now()->timestamp,
        ]);

        config()->set('legacy-bridge.payload.format', 'php_session');

        $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
            ->get('/protected')
            ->assertOk();

        $this->assertAuthenticated();
    });

    it('bridges a json format payload', function (): void {
        $user = User::factory()->create();

        DB::table('legacy_sessions')->insert([
            'id'            => 'test-session',
            'payload'       => base64_encode(json_encode(['user_id' => $user->id])),
            'last_activity' => now()->timestamp,
        ]);

        config()->set('legacy-bridge.payload.format', 'json');

        $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
            ->get('/protected')
            ->assertOk();

        $this->assertAuthenticated();
    });

    it('bridges a nested payload using dot notation key resolver', function (): void {
        $user = User::factory()->create();

        DB::table('legacy_sessions')->insert([
            'id'            => 'test-session',
            'payload'       => base64_encode(serialize(['auth' => ['user' => ['id' => $user->id]]])),
            'last_activity' => now()->timestamp,
        ]);

        config()->set('legacy-bridge.resolver.driver', 'key');
        config()->set('legacy-bridge.resolver.key', 'auth.user.id');

        $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
            ->get('/protected')
            ->assertOk();

        $this->assertAuthenticated();
    });

    it('dispatches PayloadDecodeFailed when payload is corrupt', function (): void {
        DB::table('legacy_sessions')->insert([
            'id'            => 'test-session',
            'payload'       => 'not_valid_at_all####',
            'last_activity' => now()->timestamp,
        ]);

        $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
            ->get('/protected')
            ->assertRedirect('/login');

        Event::assertDispatched(LegacySessionBridgeFailed::class, function ($event) {
            return $event->reason === BridgeFailureReason::PayloadDecodeFailed;
        });
    });
});

// ---------------------------------------------------------------------------
// Invalidation strategies
// ---------------------------------------------------------------------------

describe('invalidation', function (): void {
    it('deletes the legacy session after write when strategy is after_write', function (): void {
        $user = User::factory()->create();

        config()->set('legacy-bridge.invalidation_strategy', InvalidationStrategy::AfterWrite->value);

        legacySession(['user_id' => $user->id]);

        $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
            ->get('/protected')
            ->assertOk();

        expect(DB::table('legacy_sessions')->where('id', 'test-session')->exists())->toBeFalse();
    });

    it('deletes the legacy session immediately when strategy is immediate', function (): void {
        $user = User::factory()->create();

        config()->set('legacy-bridge.invalidation_strategy', InvalidationStrategy::Immediate->value);

        legacySession(['user_id' => $user->id]);

        $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
            ->get('/protected')
            ->assertOk();

        expect(DB::table('legacy_sessions')->where('id', 'test-session')->exists())->toBeFalse();
    });

    it('preserves the legacy session when strategy is never', function (): void {
        $user = User::factory()->create();

        config()->set('legacy-bridge.invalidation_strategy', InvalidationStrategy::Never->value);

        legacySession(['user_id' => $user->id]);

        $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
            ->get('/protected')
            ->assertOk();

        expect(DB::table('legacy_sessions')->where('id', 'test-session')->exists())->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// Context carry
// ---------------------------------------------------------------------------

describe('context carry', function (): void {
    it('carries specified keys from the legacy session into the laravel session', function (): void {
        $user = User::factory()->create();

        config()->set('legacy-bridge.context.carry_keys', ['locale', 'timezone']);

        legacySession([
            'user_id'  => $user->id,
            'locale'   => 'fr',
            'timezone' => 'Europe/Paris',
        ]);

        $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
            ->get('/protected')
            ->assertOk();

        expect(session('locale'))->toBe('fr')
            ->and(session('timezone'))->toBe('Europe/Paris');
    });

    it('does not carry keys not listed in carry_keys', function (): void {
        $user = User::factory()->create();

        config()->set('legacy-bridge.context.carry_keys', ['locale']);

        legacySession([
            'user_id' => $user->id,
            'locale'  => 'fr',
            'cart_id' => 99,
        ]);

        $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
            ->get('/protected')
            ->assertOk();

        expect(session('cart_id'))->toBeNull();
    });

    it('carries flash data when context flash is enabled', function (): void {
        $user = User::factory()->create();

        config()->set('legacy-bridge.context.flash', true);

        legacySession([
            'user_id' => $user->id,
            'status'  => 'Profile updated.',
            '_flash'  => ['new' => ['status'], 'old' => []],
        ]);

        $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
            ->get('/protected')
            ->assertOk();

        expect(session('status'))->toBe('Profile updated.');
    });
});

// ---------------------------------------------------------------------------
// BridgeContext
// ---------------------------------------------------------------------------

describe('bridge context', function (): void {
    it('includes request context in the failed event', function (): void {
        $this->withUnencryptedCookies(['PHPSESSID' => 'ghost'])
            ->get('/protected');

        Event::assertDispatched(LegacySessionBridgeFailed::class, function ($event) {
            return isset($event->context->requestContext['ip'])
                && isset($event->context->requestContext['path'])
                && isset($event->context->requestContext['method'])
                && isset($event->context->requestContext['user_agent']);
        });
    });

    it('accumulates session id in context when session is found but user is not resolved', function (): void {
        legacySession(['some_key' => 'value']);

        $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
            ->get('/protected');

        Event::assertDispatched(LegacySessionBridgeFailed::class, function ($event) {
            return $event->context->sessionId === 'test-session';
        });
    });

    it('accumulates user id in context when auth fails', function (): void {
        legacySession(['user_id' => 99999]);

        $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
            ->get('/protected');

        Event::assertDispatched(LegacySessionBridgeFailed::class, function ($event) {
            return $event->context->userId === 99999;
        });
    });
});

// ---------------------------------------------------------------------------
// Column mapping
// ---------------------------------------------------------------------------

describe('column mapping', function (): void {
    it('reads from a custom payload column name', function (): void {
        $user = User::factory()->create();

        Schema::dropIfExists('legacy_sessions');
        Schema::create('legacy_sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->longText('data'); // custom column name
            $table->integer('last_activity');
        });

        DB::table('legacy_sessions')->insert([
            'id'            => 'test-session',
            'data'          => base64_encode(serialize(['user_id' => $user->id])),
            'last_activity' => now()->timestamp,
        ]);

        config()->set('legacy-bridge.database.columns.payload', 'data');

        $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
            ->get('/protected')
            ->assertOk();

        $this->assertAuthenticated();
    });
});
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\User;

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------

it('bridges a valid legacy session and authenticates the user', function (): void {
    $user = User::factory()->create();

    legacySession(['user_id' => $user->id]);

    $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
        ->get('/protected')
        ->assertOk();

    $this->assertAuthenticated();
});

it('authenticates the correct user from the legacy session', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    legacySession(['user_id' => $userB->id]);

    $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
        ->get('/protected')
        ->assertOk();

    expect(Auth::id())->toBe($userB->id);
});

it('does not authenticate when no legacy cookie is present', function (): void {
    $this->get('/protected')->assertRedirect('/login');

    $this->assertGuest();
});

it('does not authenticate when the legacy cookie does not match any session', function (): void {
    $this->withUnencryptedCookies(['PHPSESSID' => 'non-existent-session'])
        ->get('/protected')
        ->assertRedirect('/login');

    $this->assertGuest();
});

it('does not authenticate when the session has expired', function (): void {
    $user = User::factory()->create();

    legacySession(
        payload: ['user_id' => $user->id],
        lastActivity: now()->subMinutes(200)->timestamp, // beyond default 120m lifetime
    );

    $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
        ->get('/protected')
        ->assertRedirect('/login');

    $this->assertGuest();
});

it('does not authenticate when the user id in the payload does not exist', function (): void {
    legacySession(['user_id' => 99999]);

    $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
        ->get('/protected')
        ->assertRedirect('/login');

    $this->assertGuest();
});

it('skips bridge when user is already authenticated', function (): void {
    $user = User::factory()->create();

    // No legacy session inserted — bridge should not be needed
    $this->actingAs($user)
        ->get('/protected')
        ->assertOk();

    $this->assertAuthenticated();
});

// ---------------------------------------------------------------------------
// Payload formats
// ---------------------------------------------------------------------------

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

    config()->set('legacy-bridge.format', 'php_session');

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

    config()->set('legacy-bridge.format', 'json');

    $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
        ->get('/protected')
        ->assertOk();

    $this->assertAuthenticated();
});

it('bridges a nested payload using dot notation resolver', function (): void {
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

it('returns guest when payload is empty', function (): void {
    DB::table('legacy_sessions')->insert([
        'id'            => 'test-session',
        'payload'       => base64_encode(serialize([])),
        'last_activity' => now()->timestamp,
    ]);

    $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
        ->get('/protected')
        ->assertRedirect('/login');

    $this->assertGuest();
});

// ---------------------------------------------------------------------------
// Invalidation
// ---------------------------------------------------------------------------

it('deletes the legacy session after a successful bridge when strategy is after_write', function (): void {
    $user = User::factory()->create();

    config()->set('legacy-bridge.invalidation', 'after_write');

    legacySession(['user_id' => $user->id]);

    $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
        ->get('/protected')
        ->assertOk();

    expect(DB::table('legacy_sessions')->where('id', 'test-session')->exists())->toBeFalse();
});

it('deletes the legacy session immediately when strategy is immediate', function (): void {
    $user = User::factory()->create();

    config()->set('legacy-bridge.invalidation', 'immediate');

    legacySession(['user_id' => $user->id]);

    $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
        ->get('/protected')
        ->assertOk();

    expect(DB::table('legacy_sessions')->where('id', 'test-session')->exists())->toBeFalse();
});

it('preserves the legacy session when strategy is never', function (): void {
    $user = User::factory()->create();

    config()->set('legacy-bridge.invalidation', 'never');

    legacySession(['user_id' => $user->id]);

    $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
        ->get('/protected')
        ->assertOk();

    expect(DB::table('legacy_sessions')->where('id', 'test-session')->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Context carry
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// Resilience
// ---------------------------------------------------------------------------

it('does not throw when the legacy db connection fails', function (): void {
    config()->set('legacy-bridge.connection', 'nonexistent');

    $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
        ->get('/protected')
        ->assertRedirect('/login'); // fails gracefully, guest flow

    $this->assertGuest();
});

it('does not throw when the payload cannot be decoded', function (): void {
    DB::table('legacy_sessions')->insert([
        'id'            => 'test-session',
        'payload'       => 'not_valid_at_all####',
        'last_activity' => now()->timestamp,
    ]);

    $this->withUnencryptedCookies(['PHPSESSID' => 'test-session'])
        ->get('/protected')
        ->assertRedirect('/login');

    $this->assertGuest();
});

it('handles multiple concurrent sessions for different users independently', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    legacySession(['user_id' => $userA->id], 'session-a');
    legacySession(['user_id' => $userB->id], 'session-b');

    $this->withUnencryptedCookies(['PHPSESSID' => 'session-a'])
        ->get('/protected')
        ->assertOk();
    expect(Auth::id())->toBe($userA->id);

    // Reset auth state between requests
    Auth::logout();

    $this->withUnencryptedCookies(['PHPSESSID' => 'session-b'])
        ->get('/protected')
        ->assertOk();
    expect(Auth::id())->toBe($userB->id);
});

// ---------------------------------------------------------------------------
// Laravel encryped cookie
// ---------------------------------------------------------------------------
it('bridges a laravel encrypted cookie', function (): void {
    config()->set('legacy-bridge.format', 'laravel');
    config()->set('legacy-bridge.cookie_encryption', 'laravel');
    config()->set('legacy-bridge.legacy_app_key', config('app.key'));

    User::factory()->create();

    DB::table('legacy_sessions')->insert([
        'user_id'       => 1,
        'id'            => '4yGsgGexAIrwYcZ4Ak0bOSMQXvFADzr7BWzE2TZD',
        'payload'       => 'eyJfdG9rZW4iOiJVcDJDaW1RZU42UnNPaG9SaHZTZTloUVFaRWNjcjFFNVlZOWhEUTM4IiwibG9naW5fd2ViXzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiOjEsIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfSwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2xvY2FsaG9zdDo4MDAwIiwicm91dGUiOiJob21lIn19',
        'last_activity' => now()->timestamp,
    ]);

    $this->withUnencryptedCookies(['PHPSESSID' => urldecode('eyJpdiI6InNCQVo1OWZuSTZZZEdpbXZQVjBUSmc9PSIsInZhbHVlIjoiajZMa0VyZDl3QjVnZUtuUyttZmpjSWE0NXh4ZlZkYWlDcFBQcU5aQkRlQnZpbHpHZFExNzQyQjRFYTg2NDRkcVVzaG45ZWZMTFNFL3A2WGp1WW1SNFBxU25XWCt2eDhXQm1kWG1KaDU2cVMvRm9CSGNBaTVaelpEQklReHl5OVciLCJtYWMiOiIxMmMxNjVkZjEzYTRjNDkxNjZiYjllOWQ2N2ViNGE5NTA5YWJmNGE2MDZjMmY3YWVhZWNiMzQ3NTViOTRiNDMzIiwidGFnIjoiIn0%3D')])
        ->get('/protected')
        ->assertOk();

    $this->assertAuthenticated();
});

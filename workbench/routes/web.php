<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\User;

Route::get('/login', fn () => 'login')->middleware('guest')->name('login');
Route::get('/', fn () => Auth::guard()->check() ? 'Logged in!' : 'Not logged in...')->middleware('auth');

Route::get('/create-legacy-session', function () {
    DB::table('legacy_sessions')->insertOrIgnore([
        'id'            => 'legacy-test-session',
        'payload'       => base64_encode(serialize(['user_id' => User::query()->value('id')])),
        'last_activity' => now()->timestamp,
    ]);

    Cookie::queue(
        'legacy-test-session',
        'legacy-test-session',
        120,
        config('session.path'),
        config('session.domain'),
        config('session.secure'),
        false,
        false,
        config('session.same_site') ?? null
    );

    return redirect('/');
});

Route::get('/config', function () {
    dump(config('legacy-bridge'));
});

Route::get('/clear-session', function () {
    Auth::logout();

    Cookie::queue(Cookie::forget('legacy-test-session'));

    DB::table('legacy_sessions')->where('id', 'legacy-test-session')->delete();

    return redirect('/');
});

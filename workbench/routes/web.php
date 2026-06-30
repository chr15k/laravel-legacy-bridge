<?php

use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    dump(config('legacy-bridge'));
    // dump(Cookie::get('legacy-test-session'));
});

Route::get('create-legacy-cookie', function () {
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
});

Route::get('/login', fn () => 'login')->name('login');
Route::get('/protected', fn () => 'ok')->middleware('auth');

// Route::get('/create-session', function () {
//     Auth::login(User::first());

//     return 'Session created: '.session()->getId();
// });

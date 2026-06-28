<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\User;

// Route::get('/login', fn () => 'login')->name('login');

// Route::get('/protected', fn () => 'ok')->middleware('auth');

Route::get('/create-session', function () {
    Auth::login(User::first());

    return 'Session created: '.session()->getId();
});

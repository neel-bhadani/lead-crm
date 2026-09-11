<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\SignupController;
use Illuminate\Support\Facades\Route;

/*
 | Sign in, sign up, sign out, and nothing else.
 |
 | The Breeze scaffolding that shipped with the app — register, forgot/reset
 | password, email verification, confirm password — was a second door into the
 | users table that answered to none of the rules the Users page enforces, and
 | it had no Vue pages behind it either. It is gone; those URLs are 404 now, and
 | /register in particular stays one.
 |
 | Sign-up is a request for an account, not an account. SignupRequest holds it
 | to the same role rule as UserRequest, and the row it writes is switched off
 | and `pending` until an admin approves it on the Users page.
 */

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    // the same page as /login, opened on its Sign up tab
    Route::get('signup', [SignupController::class, 'create'])->name('signup');
    // rate limited inside SignupRequest, before validation runs
    Route::post('signup', [SignupController::class, 'store'])->name('signup.store');
    Route::get('signup/submitted', [SignupController::class, 'submitted'])->name('signup.submitted');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});

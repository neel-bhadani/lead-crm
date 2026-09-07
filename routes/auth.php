<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use Illuminate\Support\Facades\Route;

/*
 | Sign in and sign out, and nothing else.
 |
 | Accounts in this CRM are made by an admin on the Users page, where UserRequest
 | and config('crm.staff_roles') decide what a new one may be. The Breeze
 | scaffolding that shipped with the app — register, forgot/reset password, email
 | verification, confirm password — was a second door into the same table that
 | answered to none of that, and it had no Vue pages behind it either. It is
 | gone; those URLs are 404 now.
 */

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});

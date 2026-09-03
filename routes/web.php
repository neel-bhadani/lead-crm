<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TodoController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware(['auth'])->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    /* ---------------- leads ---------------- */

    Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
    Route::get('/leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
    Route::post('/leads/check-duplicate', [LeadController::class, 'checkDuplicate'])
        ->name('leads.check-duplicate');

    // telecallers cannot create or edit leads
    Route::middleware('role:admin,salesperson')->group(function () {
        Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');
        Route::put('/leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
    });

    Route::middleware('role:admin')->group(function () {
        Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])->name('leads.destroy');
        Route::delete('/todos/{todo}', [TodoController::class, 'destroy'])->name('todos.destroy');
    });

    /* ---------------- to-do ---------------- */

    Route::get('/todos', [TodoController::class, 'index'])->name('todos.index');
    Route::post('/todos', [TodoController::class, 'store'])->name('todos.store');
    Route::put('/todos/{todo}', [TodoController::class, 'update'])->name('todos.update');
    Route::post('/todos/{todo}/complete', [TodoController::class, 'complete'])
        ->name('todos.complete');

    /* ---------------- profile (from Breeze) ---------------- */

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__ . '/auth.php';

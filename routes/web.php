<?php

use App\Http\Controllers\Admin\UserEmailController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailChangeLinkController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

Route::middleware(['auth', 'verified', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::put('users/{user}/email', [UserEmailController::class, 'update'])->name('users.email.update');
});

// The links emailed during an email change. Signed URLs authorize them, so
// they work without being logged in; see EmailChangeLinkController.
Route::controller(EmailChangeLinkController::class)->prefix('email-change')->name('email-change.')->group(function () {
    Route::get('done', 'done')->name('done');
    Route::get('{emailChange}/new', 'showNew')->whereNumber('emailChange')->name('new.show');
    Route::post('{emailChange}/new', 'confirmNew')->whereNumber('emailChange')->name('new.confirm')->middleware('throttle:10,1');
    Route::get('{emailChange}/current', 'showCurrent')->whereNumber('emailChange')->name('current.show');
    Route::post('{emailChange}/current', 'approveCurrent')->whereNumber('emailChange')->name('current.approve')->middleware('throttle:10,1');
    Route::post('{emailChange}/current/deny', 'denyCurrent')->whereNumber('emailChange')->name('current.deny')->middleware('throttle:10,1');
});

require __DIR__.'/settings.php';

<?php

use App\Http\Controllers\Admin\CollegeController;
use App\Http\Controllers\Admin\ProgramController;
use App\Http\Controllers\Admin\RoomController;
use App\Http\Controllers\Admin\SubjectController;
use App\Http\Controllers\Admin\TermController;
use App\Http\Controllers\Admin\UserEmailController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailChangeLinkController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('calendar', [CalendarController::class, 'index'])->name('calendar.index');

    // Who may change which event is CalendarEventPolicy's call.
    Route::middleware('role:admin,dean')->group(function () {
        Route::post('calendar/events', [CalendarController::class, 'store'])->name('calendar.events.store');
        Route::put('calendar/events/{event}', [CalendarController::class, 'update'])->name('calendar.events.update');
        Route::delete('calendar/events/{event}', [CalendarController::class, 'destroy'])->name('calendar.events.destroy');
    });
});

Route::middleware(['auth', 'verified', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::put('users/{user}/email', [UserEmailController::class, 'update'])->name('users.email.update');

    Route::resource('colleges', CollegeController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::resource('colleges.programs', ProgramController::class)->shallow()->only(['store', 'update', 'destroy']);
    Route::resource('subjects', SubjectController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('rooms', RoomController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('terms', TermController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('terms/{term}/close', [TermController::class, 'close'])->name('terms.close');
    Route::delete('terms/{term}/close', [TermController::class, 'reopen'])->name('terms.reopen');
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

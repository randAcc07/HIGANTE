<?php

use App\Http\Controllers\AccountSetupController;
use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\CollegeController;
use App\Http\Controllers\Admin\ProgramController;
use App\Http\Controllers\Admin\RoomController;
use App\Http\Controllers\Admin\SubjectController;
use App\Http\Controllers\Admin\TermController;
use App\Http\Controllers\Admin\UserEmailController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Dean\AttendanceController as DeanAttendanceController;
use App\Http\Controllers\Dean\BatchController;
use App\Http\Controllers\Dean\ExcuseController as DeanExcuseController;
use App\Http\Controllers\Dean\LearnerController as DeanLearnerController;
use App\Http\Controllers\Dean\OfferingController;
use App\Http\Controllers\Dean\PlacementController;
use App\Http\Controllers\Dean\ScheduleController;
use App\Http\Controllers\Dean\SectionController;
use App\Http\Controllers\Dean\StandingController;
use App\Http\Controllers\Dean\TimetableController;
use App\Http\Controllers\EmailChangeLinkController;
use App\Http\Controllers\ExcuseDocumentController;
use App\Http\Controllers\Learner\ExcuseController as LearnerExcuseController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Registrar\LearnerController;
use App\Http\Controllers\Registrar\RegistrationController;
use App\Http\Controllers\Teacher\ClassController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => to_route('dashboard'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::get('calendar/events/{event}/memo', [CalendarController::class, 'memo'])->name('calendar.events.memo');
    Route::get('excuses/documents/{document}', [ExcuseDocumentController::class, 'show'])->name('excuses.documents.show');
    Route::post('notifications/read', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');

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
    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::put('accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
    Route::put('accounts/{account}/status', [AccountController::class, 'status'])->name('accounts.status');
    Route::post('accounts/{account}/setup-link', [AccountController::class, 'setupLink'])->name('accounts.setup-link');
    Route::post('terms/{term}/close', [TermController::class, 'close'])->name('terms.close');
    Route::delete('terms/{term}/close', [TermController::class, 'reopen'])->name('terms.reopen');
});

Route::middleware(['auth', 'verified', 'role:registrar'])->prefix('registrar')->name('registrar.')->group(function () {
    Route::get('learners', [LearnerController::class, 'index'])->name('learners.index');
    Route::post('learners', [LearnerController::class, 'store'])->name('learners.store');
    Route::put('learners/{learner}', [LearnerController::class, 'update'])->name('learners.update');
    Route::put('learners/{learner}/status', [LearnerController::class, 'status'])->name('learners.status');
    Route::post('learners/{learner}/setup-link', [LearnerController::class, 'setupLink'])->name('learners.setup-link');
    Route::get('registrations', [RegistrationController::class, 'index'])->name('registrations.index');
    Route::post('terms/{term}/registrations', [RegistrationController::class, 'store'])->name('registrations.store');
    Route::put('registrations/{registration}', [RegistrationController::class, 'update'])->name('registrations.update');
    Route::post('registrations/{registration}/withdraw', [RegistrationController::class, 'withdraw'])->name('registrations.withdraw');
    Route::delete('registrations/{registration}', [RegistrationController::class, 'destroy'])->name('registrations.destroy');
});

// Scheduling: each change is authorized through its section (SectionPolicy).
Route::middleware(['auth', 'verified', 'role:dean'])->prefix('dean')->name('dean.')->group(function () {
    Route::resource('sections', SectionController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::post('sections/{section}/offerings', [OfferingController::class, 'store'])->name('offerings.store');
    Route::put('offerings/{offering}', [OfferingController::class, 'update'])->name('offerings.update');
    Route::delete('offerings/{offering}', [OfferingController::class, 'destroy'])->name('offerings.destroy');
    Route::post('offerings/{offering}/batches', [BatchController::class, 'store'])->name('batches.store');
    Route::put('batches/{batch}', [BatchController::class, 'update'])->name('batches.update');
    Route::delete('batches/{batch}', [BatchController::class, 'destroy'])->name('batches.destroy');
    Route::post('offerings/{offering}/schedules', [ScheduleController::class, 'store'])->name('schedules.store');
    Route::put('schedules/{schedule}', [ScheduleController::class, 'update'])->name('schedules.update');
    Route::delete('schedules/{schedule}', [ScheduleController::class, 'destroy'])->name('schedules.destroy');
    Route::get('timetables', [TimetableController::class, 'index'])->name('timetables.index');
    Route::put('sections/{section}/batches', [BatchController::class, 'roster'])->name('batches.roster');

    // Placement: authorized through the learner's registration (TermRegistrationPolicy).
    Route::get('attendance', [DeanAttendanceController::class, 'index'])->name('attendance.index');
    Route::get('excuses', [DeanExcuseController::class, 'index'])->name('excuses.index');
    Route::get('excuses/{excuse}', [DeanExcuseController::class, 'show'])->name('excuses.show');
    Route::post('excuses/{excuse}/approve', [DeanExcuseController::class, 'approve'])->name('excuses.approve');
    Route::post('excuses/{excuse}/reject', [DeanExcuseController::class, 'reject'])->name('excuses.reject');
    Route::post('excuses/{excuse}/revoke', [DeanExcuseController::class, 'revoke'])->name('excuses.revoke');
    Route::get('standing', [StandingController::class, 'index'])->name('standing.index');
    Route::post('enrollments/{enrollment}/drop', [StandingController::class, 'drop'])->name('standing.drop');
    Route::post('enrollments/{enrollment}/dismiss', [StandingController::class, 'dismiss'])->name('standing.dismiss');
    Route::post('enrollments/{enrollment}/reinstate', [StandingController::class, 'reinstate'])->name('standing.reinstate');
    Route::get('learners', [DeanLearnerController::class, 'index'])->name('learners.index');
    Route::get('learners/{registration}', [DeanLearnerController::class, 'show'])->name('learners.show');
    Route::put('learners/{registration}/section', [PlacementController::class, 'section'])->name('learners.section');
    Route::post('learners/{registration}/enrollments', [PlacementController::class, 'enroll'])->name('learners.enroll');
    Route::put('enrollments/{enrollment}', [PlacementController::class, 'batch'])->name('enrollments.update');
    Route::delete('enrollments/{enrollment}', [PlacementController::class, 'remove'])->name('enrollments.destroy');
});

Route::middleware(['auth', 'verified', 'role:learner'])->prefix('learner')->name('learner.')->group(function () {
    Route::get('excuses', [LearnerExcuseController::class, 'index'])->name('excuses.index');
    Route::post('excuses', [LearnerExcuseController::class, 'store'])->name('excuses.store');
});

Route::middleware(['auth', 'verified', 'role:teacher'])->prefix('teacher')->name('teacher.')->group(function () {
    Route::get('classes', [ClassController::class, 'index'])->name('classes.index');
});

// A class list on a date: its teacher takes attendance, the dean of its
// college corrects it (SchedulePolicy).
Route::middleware(['auth', 'verified', 'role:teacher,dean'])->group(function () {
    Route::get('attendance/{schedule}/{date}', [AttendanceController::class, 'show'])->where('date', '\d{4}-\d{2}-\d{2}')->name('attendance.show');
    Route::put('attendance/{schedule}/{date}', [AttendanceController::class, 'update'])->where('date', '\d{4}-\d{2}-\d{2}')->name('attendance.update');
});

// Account setup links. Signed URLs authorize them; see AccountSetupController.
Route::get('account-setup/{user}', [AccountSetupController::class, 'show'])->whereNumber('user')->name('account-setup.show');
Route::post('account-setup/{user}', [AccountSetupController::class, 'store'])->whereNumber('user')->middleware('throttle:10,1')->name('account-setup.store');

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

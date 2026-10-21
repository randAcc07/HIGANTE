<?php

use App\Http\Controllers\Registrar\LearnerImportController;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The cron entry runs schedule:run every minute. Without this, each run reads
// the pause flag from the cache (the database here), which keeps a database
// that sleeps when idle awake around the clock; due tasks still run. It gives
// up schedule:pause and schedule:interrupt, which aren't used.
Schedule::withoutInterruptionPolling();

// Removes expired email change requests (EmailChange::prunable).
Schedule::command('model:prune')->daily();

// Checked-but-not-imported uploads and unclaimed setup-link files.
Schedule::call(function (): void {
    foreach (Storage::files(LearnerImportController::DIRECTORY) as $file) {
        if (Storage::lastModified($file) < now()->subDay()->getTimestamp()) {
            Storage::delete($file);
        }
    }
})->daily()->name('prune-learner-imports');

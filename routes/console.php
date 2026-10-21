<?php

use App\Http\Controllers\Registrar\LearnerImportController;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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

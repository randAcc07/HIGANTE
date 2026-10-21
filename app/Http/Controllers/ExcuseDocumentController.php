<?php

namespace App\Http\Controllers;

use App\Models\AbsenceExcuseDocument;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A file attached to an excuse, for the learner and their dean.
 */
class ExcuseDocumentController extends Controller
{
    public function show(AbsenceExcuseDocument $document): StreamedResponse
    {
        Gate::authorize('view', $document->absenceExcuse);
        abort_unless(Storage::exists($document->path), 404);

        return Storage::response($document->path, $document->original_name, ['Content-Type' => $document->mime_type]);
    }
}

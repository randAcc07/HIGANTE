<?php

namespace App\Http\Controllers\Learner;

use App\Attendance\Excuses;
use App\Enums\ExcuseReason;
use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ExcuseDocumentController;
use App\Models\AbsenceExcuse;
use App\Models\AbsenceExcuseDocument;
use App\Models\Learner;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A learner's own excuses: what they submitted and how each was decided.
 * Once submitted an excuse is never deleted; the dean decides it.
 */
class ExcuseController extends Controller
{
    /** The longest range one excuse may cover. */
    public const MAX_DAYS = 31;

    public function index(Request $request): Response
    {
        $learner = self::learner($request);

        $excuses = AbsenceExcuse::query()
            ->with('documents')
            ->withCount('attendanceRecords')
            ->where('learner_id', $learner->id)
            ->latest()
            ->get()
            ->map(fn (AbsenceExcuse $excuse): array => self::row($excuse));

        return Inertia::render('learner/excuses/Index', [
            'excuses' => $excuses,
            'reasons' => ExcuseReason::cases(),
            'maxDays' => self::MAX_DAYS,
            'dueWithinDays' => AbsenceExcuse::DUE_WITHIN_DAYS,
        ]);
    }

    public function store(Request $request, Excuses $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $learner = self::learner($request);

        $validated = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'reason' => ['required', Rule::enum(ExcuseReason::class)],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'files' => ['array', 'max:10'],
            'files.*' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ], [
            'date_to.after_or_equal' => __('The excuse cannot end before it starts.'),
            'files.*.mimes' => __('Attach PDFs or photos (JPG, PNG, WebP).'),
            'files.*.max' => __('Each file can be at most 10 MB.'),
        ]);

        $this->checkRange($learner, $validated['date_from'], $validated['date_to']);

        /** @var list<UploadedFile> $files */
        $files = array_values($request->file('files', []));
        $excuse = $service->submit($learner, $validated, $files, $user);

        Inertia::flash('toast', $excuse->daysLate() > 0
            ? ['type' => 'warning', 'message' => __('Excuse submitted, :days days past the :due-day deadline. Your dean will see it as late.', ['days' => $excuse->daysLate(), 'due' => AbsenceExcuse::DUE_WITHIN_DAYS])]
            : ['type' => 'success', 'message' => __('Excuse submitted. Your dean will review it.')]);

        return back();
    }

    /**
     * An excuse covers days of a term the learner is registered for, at
     * most MAX_DAYS long, and not in a closed term.
     */
    private function checkRange(Learner $learner, string $from, string $to): void
    {
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        $errors = [];

        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            $errors['date_to'] = __('One excuse can cover at most :days days.', ['days' => self::MAX_DAYS]);
        }

        $terms = Term::where('starts_on', '<=', $to)->where('ends_on', '>=', $from)
            ->whereHas('termRegistrations', fn ($query) => $query->where('learner_id', $learner->id)->where('status', RegistrationStatus::Registered))
            ->get();

        if ($terms->isEmpty()) {
            $errors['date_from'] = __('You aren\'t registered for a term on those days.');
        } elseif ($terms->contains(fn (Term $term): bool => $term->isClosed())) {
            $errors['date_from'] = __('That term is closed.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * How an excuse reads on the learner's and the dean's pages.
     *
     * @return array<string, mixed>
     */
    public static function row(AbsenceExcuse $excuse): array
    {
        return [
            'id' => $excuse->id,
            'date_from' => $excuse->date_from->toDateString(),
            'date_to' => $excuse->date_to->toDateString(),
            'reason' => $excuse->reason->value,
            'remarks' => $excuse->remarks,
            'status' => $excuse->status->value,
            'submitted_on' => $excuse->created_at?->toDateString(),
            // Submitted after the deadline (days past it); the dean weighs it.
            'days_late' => $excuse->daysLate(),
            'reviewed_on' => $excuse->reviewed_at?->toDateString(),
            'review_remarks' => $excuse->review_remarks,
            'revoked_on' => $excuse->revoked_at?->toDateString(),
            'revoke_remarks' => $excuse->revoke_remarks,
            'excused' => $excuse->attendance_records_count ?? null,
            'documents' => $excuse->documents->map(fn (AbsenceExcuseDocument $document): array => [
                'id' => $document->id,
                'name' => $document->original_name,
                'size' => $document->size_bytes,
                'url' => action([ExcuseDocumentController::class, 'show'], $document, false),
            ])->values(),
        ];
    }

    private static function learner(Request $request): Learner
    {
        /** @var User $user */
        $user = $request->user();

        return $user->learner ?? abort(403);
    }
}

<?php

namespace App\Http\Controllers\Learner;

use App\Attendance\Excuses;
use App\Attendance\ExcuseView;
use App\Enums\ExcuseReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExcuseRequest;
use App\Models\AbsenceExcuse;
use App\Models\Learner;
use App\Models\Term;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A learner's own excuses: what they (or a teacher for them) submitted, and
 * how each class decided. Once submitted an excuse is never deleted.
 */
class ExcuseController extends Controller
{
    public function index(Request $request): Response
    {
        $learner = self::learner($request);
        $terms = Term::orderByDesc('starts_on')->get();
        $term = $terms->firstWhere('id', $request->integer('term')) ?? Term::forViewing();

        return Inertia::render('learner/excuses/Index', [
            'terms' => $terms->map(fn (Term $term): array => ['id' => $term->id, 'label' => $term->label]),
            'term' => $term === null ? null : ['id' => $term->id, 'label' => $term->label],
            'excuses' => AbsenceExcuse::with('learner')
                ->where('learner_id', $learner->id)
                ->when($term, fn ($query, Term $term) => $query->duringTerm($term))
                ->latest()
                ->get()
                ->map(fn (AbsenceExcuse $excuse): array => ExcuseView::excuse($excuse)),
            'reasons' => ExcuseReason::cases(),
            'maxDays' => ExcuseRequest::MAX_DAYS,
            'dueWithinDays' => AbsenceExcuse::DUE_WITHIN_DAYS,
        ]);
    }

    public function store(ExcuseRequest $request, Excuses $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $learner = self::learner($request);

        /** @var list<UploadedFile> $files */
        $files = array_values($request->file('files', []));
        /** @var array{date_from: string, date_to: string, reason: string, remarks?: string|null} $data */
        $data = $request->safe()->only(['date_from', 'date_to', 'reason', 'remarks']);
        $excuse = $service->submit($learner, $data, $files, $user);

        Inertia::flash('toast', match (true) {
            $excuse->decisions()->doesntExist() => ['type' => 'warning', 'message' => __('Excuse submitted, but none of your classes meet on those days, so there is nothing to excuse.')],
            $excuse->daysLate() > 0 => ['type' => 'warning', 'message' => __('Excuse submitted, :days days past the :due-day deadline. Your teachers will see it as late.', ['days' => $excuse->daysLate(), 'due' => AbsenceExcuse::DUE_WITHIN_DAYS])],
            default => ['type' => 'success', 'message' => __('Excuse submitted. Each of your teachers decides for their class.')],
        });

        return back();
    }

    private static function learner(Request $request): Learner
    {
        /** @var User $user */
        $user = $request->user();

        return $user->learner ?? abort(403);
    }
}

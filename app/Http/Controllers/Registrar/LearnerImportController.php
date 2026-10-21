<?php

namespace App\Http\Controllers\Registrar;

use App\Actions\Accounts\AdmitLearner;
use App\Actions\Accounts\SendAccountSetupLink;
use App\Admission\LearnerSheet;
use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admitting learners from a filled-in template: the upload is checked
 * first (nothing saved; every problem listed by row and column), then
 * imported as a whole once it is clean, optionally registering everyone for
 * a term. Setup links are emailed, and offered once as a file to hand out.
 *
 * The checked upload waits in private storage under a token kept in the
 * registrar's session; uploads and links files older than a day are pruned.
 */
class LearnerImportController extends Controller
{
    public const DIRECTORY = 'learner-imports';

    public function template(LearnerSheet $sheet): BinaryFileResponse
    {
        return response()->download($sheet->template(), 'learner-admission-template.xlsx')->deleteFileAfterSend();
    }

    public function check(Request $request, LearnerSheet $sheet): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
            'register_term_id' => ['nullable', 'integer', Rule::exists(Term::class, 'id')],
        ], ['file.mimes' => __('Upload the template saved as .xlsx.')]);
        $term = $this->term($validated['register_term_id'] ?? null);

        $token = (string) Str::uuid();
        $path = $request->file('file')?->storeAs(self::DIRECTORY, "{$token}.xlsx") ?: abort(500);
        ['rows' => $rows, 'errors' => $errors] = $this->checked($sheet, $path, $term);

        if ($errors !== []) {
            Storage::delete($path);
        } else {
            $request->session()->put('learner_import', $token);
        }

        Inertia::flash('learnerImport', [
            'token' => $errors === [] ? $token : null,
            'count' => count($rows),
            'term' => $term?->label,
            'errors' => array_slice($errors, 0, 200),
            'more' => max(0, count($errors) - 200),
        ]);

        return back();
    }

    public function store(Request $request, LearnerSheet $sheet, AdmitLearner $admit, SendAccountSetupLink $setupLink): RedirectResponse
    {
        /** @var User $registrar */
        $registrar = $request->user();
        $validated = $request->validate([
            'token' => ['required', 'uuid'],
            'register_term_id' => ['nullable', 'integer', Rule::exists(Term::class, 'id')],
        ]);
        $term = $this->term($validated['register_term_id'] ?? null);
        $token = (string) $validated['token'];
        $path = self::DIRECTORY."/{$token}.xlsx";

        abort_unless($request->session()->get('learner_import') === $token && Storage::exists($path), 410, __('That check has expired. Upload the sheet again.'));

        // Checked again: things may have changed since (a learner admitted by hand).
        ['rows' => $rows, 'errors' => $errors] = $this->checked($sheet, $path, $term);

        if ($errors !== []) {
            Inertia::flash('learnerImport', ['token' => $token, 'count' => count($rows), 'term' => $term?->label, 'errors' => array_slice($errors, 0, 200), 'more' => max(0, count($errors) - 200)]);

            return back();
        }

        $programs = Program::findMany(array_unique(array_column($rows, 'program_id')))->keyBy('id');

        $admitted = DB::transaction(fn (): array => array_map(
            fn (array $row): array => $admit->handle($row, $programs[(int) $row['program_id']], $registrar, $term, $row['irregular']),
            array_values($rows),
        ));

        $links = collect($admitted)->map(fn (array $result): array => [
            'name' => $result['learner']->user->last_name.', '.$result['learner']->user->first_name,
            'student_number' => $result['learner']->student_number,
            'email' => $result['learner']->user->email,
            'url' => $setupLink->handle($result['learner']->user),
        ]);
        $sheet->linksFile($links, Storage::path(self::DIRECTORY."/{$token}-links.xlsx"));
        Storage::delete($path);
        $request->session()->put('learner_import_links', $token);
        $request->session()->forget('learner_import');

        $unsectioned = collect($admitted)->filter(fn (array $result): bool => $result['registered'] !== null && $result['registered']['registration']->section_id === null)->count();

        Inertia::flash('learnerImportDone', [
            'count' => count($admitted),
            'term' => $term?->label,
            'unsectioned' => $unsectioned,
            'links' => route('registrar.learners.import.links', $token, false),
        ]);

        return back();
    }

    /**
     * The setup links file of the registrar's last import, once.
     */
    public function links(Request $request, string $token): Response
    {
        $path = self::DIRECTORY."/{$token}-links.xlsx";
        abort_unless($request->session()->get('learner_import_links') === $token && Storage::exists($path), 404);
        $request->session()->forget('learner_import_links');

        // Single use: it is a list of working sign-in links.
        $contents = (string) Storage::get($path);
        Storage::delete($path);

        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="learner-setup-links.xlsx"',
        ]);
    }

    /**
     * The term to register for, if any; refused unless the registrar may.
     */
    private function term(mixed $id): ?Term
    {
        if ($id === null || $id === '') {
            return null;
        }

        $term = Term::findOrFail((int) $id);
        Gate::authorize('create', [TermRegistration::class, $term]);

        return $term;
    }

    /**
     * @return array{rows: array<int, array{last_name: string, first_name: string, middle_name: string|null, suffix: string|null, email: string, student_number: string, program_id: int|null, year_level: int, status: string, irregular: bool}>, errors: list<array{row: int|null, column: string|null, message: string}>}
     */
    private function checked(LearnerSheet $sheet, string $path, ?Term $term): array
    {
        ['rows' => $rows, 'errors' => $errors] = $sheet->read(Storage::path($path));

        if ($errors !== []) {
            return ['rows' => [], 'errors' => $errors];
        }

        ['learners' => $learners, 'errors' => $errors] = $sheet->check($rows, $term);

        return ['rows' => $learners, 'errors' => $errors];
    }
}

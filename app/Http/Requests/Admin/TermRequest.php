<?php

namespace App\Http\Requests\Admin;

use App\Enums\Semester;
use App\Models\AttendanceRecord;
use App\Models\Term;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A term and its attendance policy, saved together.
 */
class TermRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $term = $this->route('term');

        return [
            'school_year' => ['required', 'string', 'regex:/^\d{4}-\d{4}$/'],
            'semester' => [
                'required', Rule::enum(Semester::class),
                Rule::unique(Term::class)->where('school_year', $this->string('school_year')->value())->ignore($term instanceof Term ? $term->id : null),
            ],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'lates_per_absence' => ['required', 'integer', 'between:1,20'],
            'drop_at_absences' => ['required', 'integer', 'between:1,50'],
            'warn_at_absences' => ['present', 'array', 'max:10'],
            'warn_at_absences.*' => ['integer', 'min:1', 'distinct'],
            'teacher_edit_days' => ['nullable', 'integer', 'between:0,365'],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $errors = $validator->errors();

                if (! $errors->has('school_year') && preg_match('/^(\d{4})-(\d{4})$/', $this->string('school_year')->value(), $years) && (int) $years[2] !== (int) $years[1] + 1) {
                    $errors->add('school_year', __('The second year must follow the first, e.g. 2026-2027.'));
                }

                if (! $errors->hasAny(['warn_at_absences', 'warn_at_absences.*', 'drop_at_absences'])) {
                    foreach ($this->warnAt() as $count) {
                        if ($count >= $this->integer('drop_at_absences')) {
                            $errors->add('warn_at_absences', __('Every warning must come before the drop count.'));
                            break;
                        }
                    }
                }

                if (! $errors->hasAny(['starts_on', 'ends_on'])) {
                    $term = $this->route('term');
                    $ignoreId = $term instanceof Term ? $term->id : null;
                    $overlapping = Term::query()
                        ->where('starts_on', '<=', $this->date('ends_on')?->toDateString())
                        ->where('ends_on', '>=', $this->date('starts_on')?->toDateString())
                        ->when($ignoreId, fn ($query, int $id) => $query->whereKeyNot($id))
                        ->first();

                    if ($overlapping !== null) {
                        $errors->add('starts_on', __('These dates overlap :term.', ['term' => $overlapping->label]));
                    }

                    // Moving the dates must not strand attendance already taken.
                    if ($term instanceof Term) {
                        $outside = AttendanceRecord::query()
                            ->whereHas('schedule', fn ($schedule) => $schedule->where('term_id', $term->id))
                            ->where(fn ($query) => $query
                                ->where('class_date', '<', $this->date('starts_on')?->toDateString())
                                ->orWhere('class_date', '>', $this->date('ends_on')?->toDateString()))
                            ->selectRaw('MIN(class_date) AS first_day, MAX(class_date) AS last_day')
                            ->toBase()
                            ->first();

                        if ($outside?->first_day !== null) {
                            $errors->add('starts_on', __('Attendance is already recorded on days these dates leave out (:from to :to).', [
                                'from' => $outside->first_day,
                                'to' => $outside->last_day,
                            ]));
                        }
                    }
                }
            },
        ];
    }

    /**
     * The warning counts, ascending.
     *
     * @return list<int>
     */
    public function warnAt(): array
    {
        $counts = array_map(intval(...), array_values((array) $this->input('warn_at_absences', [])));
        sort($counts);

        return $counts;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'school_year.regex' => __('Write the school year as 2026-2027.'),
            'semester.unique' => __('That school year already has this semester.'),
            'ends_on.after' => __('The term must end after it starts.'),
        ];
    }
}

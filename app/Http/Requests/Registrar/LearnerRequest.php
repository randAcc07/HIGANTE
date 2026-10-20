<?php

namespace App\Http\Requests\Registrar;

use App\Concerns\ProfileValidationRules;
use App\Enums\LearnerStatus;
use App\Models\Learner;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A learner's account and student record. The learner's college always
 * follows their program. The email is set at admission; later it changes
 * only through the confirmed change or the admin's override.
 */
class LearnerRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $learner = $this->route('learner');
        $creating = ! $learner instanceof Learner;

        return [
            ...$this->nameRules(),
            ...($creating ? ['email' => $this->emailRules()] : []),
            'student_number' => ['required', 'string', 'max:30', Rule::unique(Learner::class)->ignore($creating ? null : $learner->id)],
            'program_id' => ['required', 'integer', Rule::exists(Program::class, 'id')],
            'year_level' => ['required', 'integer', 'between:1,6'],
            'status' => ['required', Rule::enum(LearnerStatus::class)],
            // Freshmen and transferees are registered as they are admitted.
            ...($creating ? [
                'register_term_id' => ['nullable', 'integer', Rule::exists(Term::class, 'id'), 'prohibited_unless:status,active'],
                'is_irregular' => ['boolean'],
            ] : []),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['register_term_id.prohibited_unless' => __('Only an active learner can be registered.')];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $learner = $this->route('learner');

                if (! $learner instanceof Learner || $validator->errors()->has('program_id') || $this->integer('program_id') === $learner->program_id) {
                    return;
                }

                // A shift waits until the learner's current and coming terms
                // are over: their sections belong to the old program.
                $registered = $learner->termRegistrations()
                    ->whereHas('term', fn ($term) => $term->whereNull('closed_at')->where('ends_on', '>=', now()->toDateString()))
                    ->exists();

                if ($registered) {
                    $validator->errors()->add('program_id', __('The learner is registered for a term that has not ended. Change the program after it ends.'));
                }
            },
        ];
    }

    public function program(): Program
    {
        return Program::findOrFail($this->integer('program_id'));
    }

    /**
     * The user behind the learner being edited, if any.
     */
    public function account(): ?User
    {
        $learner = $this->route('learner');

        return $learner instanceof Learner ? $learner->user : null;
    }
}

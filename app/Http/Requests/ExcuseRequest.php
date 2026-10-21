<?php

namespace App\Http\Requests;

use App\Enums\ExcuseReason;
use App\Enums\RegistrationStatus;
use App\Models\Learner;
use App\Models\Term;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * An excuse as a learner submits it, or a teacher files it for a learner
 * (then with learner_id): one day or a range of up to MAX_DAYS in a term
 * the learner is registered for, not a closed one.
 */
class ExcuseRequest extends FormRequest
{
    public const MAX_DAYS = 31;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'reason' => ['required', Rule::enum(ExcuseReason::class)],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'files' => ['array', 'max:10'],
            'files.*' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_to.after_or_equal' => __('The excuse cannot end before it starts.'),
            'files.*.mimes' => __('Attach PDFs or photos (JPG, PNG, WebP).'),
            'files.*.max' => __('Each file can be at most 10 MB.'),
        ];
    }

    /**
     * The learner the excuse is for: the signed-in learner, unless a
     * controller says otherwise (a teacher filing for one).
     */
    public function learner(): ?Learner
    {
        return $this->user()?->learner;
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $learner = $this->learner();

            if ($learner === null || $validator->errors()->hasAny(['date_from', 'date_to'])) {
                return;
            }

            $from = (string) $this->string('date_from');
            $to = (string) $this->string('date_to');

            if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1 > self::MAX_DAYS) {
                $validator->errors()->add('date_to', __('One excuse can cover at most :days days.', ['days' => self::MAX_DAYS]));
            }

            $terms = Term::where('starts_on', '<=', $to)->where('ends_on', '>=', $from)
                ->whereHas('termRegistrations', fn ($query) => $query->where('learner_id', $learner->id)->where('status', RegistrationStatus::Registered))
                ->get();

            if ($terms->isEmpty()) {
                $validator->errors()->add('date_from', $this->user()?->learner?->id === $learner->id
                    ? __('You aren\'t registered for a term on those days.')
                    : __('The learner isn\'t registered for a term on those days.'));
            } elseif ($terms->contains(fn (Term $term): bool => $term->isClosed())) {
                $validator->errors()->add('date_from', __('That term is closed.'));
            }
        }];
    }
}

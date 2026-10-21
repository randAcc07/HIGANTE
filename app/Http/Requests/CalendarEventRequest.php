<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\Program;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Whose calendar an event belongs to is not part of the form: the admin's
 * events are campus-wide and a dean's belong to their college.
 */
class CalendarEventRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'suspends_classes' => ['required', 'boolean'],
            // A window narrows a suspension to the classes that start in it.
            'suspends_from' => ['nullable', 'date_format:H:i', 'prohibited_if:suspends_classes,false'],
            'suspends_until' => ['nullable', 'date_format:H:i', 'prohibited_if:suspends_classes,false', 'after:suspends_from'],
            // Narrowing to some programs / year levels (none = everyone in scope).
            'program_ids' => ['array', 'prohibited_if:suspends_classes,false'],
            'program_ids.*' => ['integer', 'distinct', Rule::exists(Program::class, 'id')->when($this->user()?->role === UserRole::Dean, fn ($rule) => $rule->where('college_id', $this->user()?->college_id))],
            'year_levels' => ['array', 'prohibited_if:suspends_classes,false'],
            'year_levels.*' => ['integer', 'distinct', 'between:1,6'],
            'memo_number' => ['nullable', 'string', 'max:60'],
            'memo' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'remove_memo' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_on.after_or_equal' => __('The event cannot end before it starts.'),
            'suspends_until.after' => __('The suspension must end after it starts.'),
            'suspends_from.prohibited_if' => __('Only a suspension has class times.'),
            'suspends_until.prohibited_if' => __('Only a suspension has class times.'),
            'memo.mimes' => __('The memo must be a PDF.'),
            'program_ids.*.exists' => __('Choose programs of your college.'),
            'program_ids.prohibited_if' => __('Only a suspension can be narrowed to programs.'),
            'year_levels.prohibited_if' => __('Only a suspension can be narrowed to year levels.'),
        ];
    }
}

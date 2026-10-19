<?php

namespace App\Http\Requests\Admin;

use App\Models\College;
use App\Models\Subject;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Subject codes are unique within a college, so every college can have its
 * own "GE 1". The college is chosen when the subject is created and never
 * changes, since its offerings belong to that college's sections.
 */
class SubjectRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string|Closure>
     */
    public function rules(): array
    {
        $subject = $this->route('subject');
        $collegeId = $subject instanceof Subject ? $subject->college_id : $this->integer('college_id');

        return [
            ...($subject instanceof Subject ? [] : ['college_id' => ['required', 'integer', Rule::exists(College::class, 'id')]]),
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique(Subject::class)->where('college_id', $collegeId)->ignore($subject instanceof Subject ? $subject->id : null),
            ],
            'title' => ['required', 'string', 'max:150'],
            'lecture_units' => ['required', 'numeric', 'decimal:0,1', 'min:0', 'max:99.9'],
            'laboratory_units' => ['required', 'numeric', 'decimal:0,1', 'min:0', 'max:99.9'],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->hasAny(['lecture_units', 'laboratory_units'])
                    && $this->float('lecture_units') + $this->float('laboratory_units') <= 0) {
                    $validator->errors()->add('lecture_units', __('A subject needs lecture or laboratory units.'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['code.unique' => __('This college already has a subject with that code.')];
    }
}

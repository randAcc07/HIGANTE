<?php

namespace App\Http\Requests\Admin;

use App\Models\Program;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A program's college comes from the route when it is created and never
 * changes: moving it would move its learners and sections to another
 * college.
 */
class ProgramRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $program = $this->route('program');

        return [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique(Program::class)->ignore($program instanceof Program ? $program->id : null)],
            'name' => ['required', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['code.regex' => __('Use letters, digits and dashes only.')];
    }
}

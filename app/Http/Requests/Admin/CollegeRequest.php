<?php

namespace App\Http\Requests\Admin;

use App\Models\College;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CollegeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $college = $this->route('college');
        $ignore = $college instanceof College ? $college->id : null;

        return [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique(College::class)->ignore($ignore)],
            'name' => ['required', 'string', 'max:150', Rule::unique(College::class)->ignore($ignore)],
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

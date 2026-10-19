<?php

namespace App\Http\Requests\Dean;

use App\Models\Program;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A section's term, program and year level are set when it is made and never
 * change (registrations point at them); its name and capacity can.
 */
class SectionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $section = $this->route('section');
        $creating = ! $section instanceof Section;
        /** @var User $user */
        $user = $this->user();

        return [
            ...($creating ? [
                'term_id' => ['required', 'integer', Rule::exists(Term::class, 'id')],
                'program_id' => ['required', 'integer', Rule::exists(Program::class, 'id')->where('college_id', $user->college_id)],
                'year_level' => ['required', 'integer', 'between:1,6'],
            ] : []),
            'name' => [
                'required', 'string', 'max:30',
                Rule::unique(Section::class)
                    ->where('term_id', $creating ? $this->integer('term_id') : $section->term_id)
                    ->where('program_id', $creating ? $this->integer('program_id') : $section->program_id)
                    ->ignore($creating ? null : $section->id),
            ],
            'capacity' => ['nullable', 'integer', 'between:1,1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => __('This program already has a section with that name this term.'),
            'program_id.exists' => __('Choose one of your college\'s programs.'),
        ];
    }
}

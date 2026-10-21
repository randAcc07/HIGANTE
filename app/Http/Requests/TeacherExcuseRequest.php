<?php

namespace App\Http\Requests;

use App\Models\Learner;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * An excuse a teacher files for a learner (in one of their classes; the
 * controller checks that).
 */
class TeacherExcuseRequest extends ExcuseRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'learner_id' => ['required', 'integer', Rule::exists(Learner::class, 'id')],
            ...parent::rules(),
        ];
    }

    public function learner(): ?Learner
    {
        return Learner::find($this->integer('learner_id'));
    }
}

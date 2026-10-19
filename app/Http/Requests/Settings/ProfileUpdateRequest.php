<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * Staff may change their own name; a learner's name is a school record
     * that only the registrar changes, so learners may not send one.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        if ($user->role->isStaff()) {
            return $this->profileRules($user->id);
        }

        return [
            ...array_map(fn (): array => ['prohibited'], $this->nameRules()),
            'email' => $this->emailRules($user->id),
        ];
    }
}

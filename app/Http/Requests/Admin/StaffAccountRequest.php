<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ProfileValidationRules;
use App\Enums\DutyStatus;
use App\Enums\UserRole;
use App\Models\College;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A staff account the admin manages: registrar, dean or teacher. Admin
 * accounts are peers with the same permissions, so they are made and kept
 * on the server (`php artisan app:create-admin`), not here. Role and college are
 * chosen when the account is made and never change (a teacher's schedules
 * and a dean's reach depend on them); the email changes only through the
 * confirmed change or the admin's override.
 */
class StaffAccountRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');
        $creating = ! $account instanceof User;
        $role = $creating ? UserRole::tryFrom($this->string('role')->value()) : $account->role;
        $teacher = $creating ? null : $account->teacher;

        return [
            ...$this->nameRules(),
            ...($creating ? [
                'role' => ['required', Rule::enum(UserRole::class)->only([UserRole::Registrar, UserRole::Dean, UserRole::Teacher])],
                'email' => $this->emailRules(),
                'college_id' => [Rule::requiredIf($role?->belongsToCollege() ?? false), 'nullable', 'integer', Rule::exists(College::class, 'id')],
            ] : []),
            ...($role === UserRole::Teacher ? [
                'employee_number' => ['required', 'string', 'max:30', Rule::unique(Teacher::class)->ignore($teacher?->id)],
                'duty_status' => ['required', Rule::enum(DutyStatus::class)],
            ] : []),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.enum' => $this->input('role') === UserRole::Admin->value
                ? __('Admin accounts are made on the server.')
                : __('Learners are admitted by the registrar.'),
            'college_id.required' => __('Deans and teachers belong to a college.'),
        ];
    }
}

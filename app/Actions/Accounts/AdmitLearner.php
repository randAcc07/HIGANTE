<?php

namespace App\Actions\Accounts;

use App\Enums\UserRole;
use App\Models\Learner;
use App\Models\Program;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use App\Registration\TermRegistrar;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Admit one learner: the account (in the program's college, its email
 * vouched for by the registrar, a password nobody knows until the setup
 * link replaces it) and the student record, optionally registered for a
 * term right away. Run it inside a transaction; the caller sends the setup
 * link once it commits.
 */
class AdmitLearner
{
    public function __construct(private TermRegistrar $registrar) {}

    /**
     * @param  array{last_name: string, first_name: string, middle_name?: string|null, suffix?: string|null, email: string, student_number: string, year_level: int, status: string}  $data
     * @return array{learner: Learner, registered: array{registration: TermRegistration, warning: string|null}|null}
     */
    public function handle(array $data, Program $program, User $registrar, ?Term $registerFor = null, bool $irregular = false): array
    {
        $account = new User([
            'last_name' => $data['last_name'],
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'suffix' => $data['suffix'] ?? null,
            'email' => $data['email'],
        ]);
        $account->forceFill([
            'role' => UserRole::Learner,
            'college_id' => $program->college_id,
            'password' => Hash::make(Str::random(40)),
            'email_verified_at' => now(),
        ])->save();

        $learner = $account->learner()->create([
            'student_number' => $data['student_number'],
            'year_level' => $data['year_level'],
            'status' => $data['status'],
            'program_id' => $program->id,
        ]);

        $registered = $registerFor === null ? null : $this->registrar->register($learner, $registerFor, $learner->year_level, $irregular, $registrar);

        return ['learner' => $learner, 'registered' => $registered];
    }
}

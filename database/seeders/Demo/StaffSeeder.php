<?php

namespace Database\Seeders\Demo;

use App\Enums\DutyStatus;
use App\Enums\UserRole;
use App\Models\College;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The admin, the registrar, one dean per college and each college's
 * teachers. Every college has one teacher on leave and one deactivated
 * account; neither gets classes. Demo logins use fixed emails and the
 * password "password".
 */
class StaffSeeder extends Seeder
{
    public const PASSWORD = 'password';

    private string $passwordHash;

    /** @var array<string, true> */
    private array $employeeNumbers = [];

    public function __construct(private FilipinoNames $names)
    {
        $this->passwordHash = Hash::make(self::PASSWORD);
    }

    public static function deanEmail(string $collegeCode): string
    {
        return 'dean.'.Str::lower($collegeCode).'@'.CampusData::EMAIL_DOMAIN;
    }

    public static function teacherEmail(string $collegeCode): string
    {
        return 'teacher.'.Str::lower($collegeCode).'@'.CampusData::EMAIL_DOMAIN;
    }

    public function run(): void
    {
        $this->user(UserRole::Admin, null, 'admin@'.CampusData::EMAIL_DOMAIN);
        $this->user(UserRole::Registrar, null, 'registrar@'.CampusData::EMAIL_DOMAIN);

        foreach (College::all() as $college) {
            $this->user(UserRole::Dean, $college, self::deanEmail($college->code));

            for ($i = 1; $i <= CampusData::TEACHERS_PER_COLLEGE; $i++) {
                $user = $this->user(UserRole::Teacher, $college, $i === 1 ? self::teacherEmail($college->code) : null);

                $onLeave = $i === CampusData::TEACHERS_PER_COLLEGE - 1;
                $deactivated = $i === CampusData::TEACHERS_PER_COLLEGE;

                if ($deactivated) {
                    $user->is_active = false;
                    $user->save();
                }

                Teacher::create([
                    'user_id' => $user->id,
                    // About a quarter are temporary staff.
                    'employee_number' => $this->employeeNumber($i > 1 && mt_rand(1, 4) === 1 ? 'T' : 'P'),
                    'duty_status' => $onLeave ? DutyStatus::OnLeave : DutyStatus::Active,
                ]);
            }
        }
    }

    /**
     * A user with a generated name; the email is generated too unless given.
     */
    public function user(UserRole $role, ?College $college, ?string $email = null): User
    {
        $person = $this->names->person();

        $user = new User;
        $user->forceFill([
            ...$person,
            'role' => $role,
            'college_id' => $college?->id,
            'email' => $email === null
                ? $this->names->email($person['first_name'], $person['last_name'])
                : $this->names->reserve($email),
            'email_verified_at' => now(),
            'password' => $this->passwordHash,
        ])->save();

        return $user;
    }

    /**
     * "URS-P-04821": P for permanent, T for temporary staff.
     */
    private function employeeNumber(string $employment): string
    {
        do {
            $number = sprintf('URS-%s-%05d', $employment, mt_rand(0, 99999));
        } while (isset($this->employeeNumbers[$number]));

        $this->employeeNumbers[$number] = true;

        return $number;
    }
}

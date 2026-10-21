<?php

namespace Database\Seeders\Demo;

use App\Enums\DutyStatus;
use App\Enums\LearnerStatus;
use App\Enums\UserRole;
use App\Models\College;
use App\Models\Learner;
use App\Models\Teacher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * When accounts were made and last signed in, so the dashboards' "recent
 * admissions" and "never signed in" lists are short and believable. Runs
 * last.
 *
 * Made: staff in the weeks before the term; learners before their entry
 * year's 1st semester (from their student number); the newly imported
 * learners three days ago; one teacher hired four days ago (added here, so
 * they have no classes yet).
 *
 * Signed in: the demo logins within the day, other staff within three
 * days, learners within two weeks, deactivated accounts and graduates long
 * ago. Never: the newly imported learners, one first year per college, the
 * new teacher and COE's teacher on leave.
 */
class AccountSeeder extends Seeder
{
    public function __construct(private StaffSeeder $staff) {}

    public function run(): void
    {
        $now = CarbonImmutable::now();
        $term = CampusData::currentTerm();
        $start = $term->starts_on;
        $thisYear = (int) substr($term->school_year, 0, 4);

        $hire = $this->staff->hire(College::where('code', CampusData::READY_COLLEGE)->sole());
        // Keyed by user id (combined with +, which keeps the keys).
        $never = [$hire->user_id => true]
            + $this->onLeave('COE')
            + array_fill_keys($this->newlyImported(), true)
            + array_fill_keys($this->firstYearsNotYetIn(), true);

        foreach (User::with('learner')->orderBy('id')->get() as $user) {
            $made = match (true) {
                $user->id === $hire->user_id => $now->subDays(4)->setTime(14, mt_rand(0, 59)),
                $user->learner !== null && isset($never[$user->id]) && $user->learner->termRegistrations()->doesntExist() => $now->subDays(3)->setTime(10, 0)->addMinutes($user->id % 60),
                $user->learner !== null => $start->subYears($thisYear - (int) substr($user->learner->student_number, 1, 4))->subDays(mt_rand(20, 70))->setTime(mt_rand(8, 16), mt_rand(0, 59)),
                $user->role === UserRole::Admin => $start->subDays(90)->setTime(9, 0),
                default => $start->subDays(mt_rand(30, 75))->setTime(mt_rand(8, 16), mt_rand(0, 59)),
            };
            $made = $made->min($now);

            $signedIn = match (true) {
                isset($never[$user->id]) => null,
                ! $user->is_active, $user->learner?->status === LearnerStatus::Graduated => $now->subDays(mt_rand(40, 120))->setTime(mt_rand(7, 20), mt_rand(0, 59)),
                (bool) preg_match('/^(admin|registrar|(dean|teacher|learner|atrisk|irregular|returnee)\.[a-z]+)@/', $user->email) => $now->subMinutes(mt_rand(60, 20 * 60)),
                $user->role === UserRole::Learner => $now->subMinutes(mt_rand(60, 14 * 24 * 60)),
                default => $now->subMinutes(mt_rand(30, 3 * 24 * 60)),
            };

            DB::table('users')->where('id', $user->id)->update([
                'created_at' => $made,
                'updated_at' => $made,
                'last_login_at' => $signedIn?->max($made),
            ]);

            if ($user->learner !== null) {
                DB::table('learners')->where('id', $user->learner->id)->update(['created_at' => $made, 'updated_at' => $made]);
            }
        }
    }

    /**
     * The college's teacher on leave, who hasn't used the system yet.
     *
     * @return array<int, true>
     */
    private function onLeave(string $collegeCode): array
    {
        $userId = Teacher::where('duty_status', DutyStatus::OnLeave)
            ->whereHas('user', fn ($user) => $user->where('is_active', true)->whereRelation('college', 'code', $collegeCode))
            ->value('user_id');

        return $userId === null ? [] : [(int) $userId => true];
    }

    /**
     * Users of the active learners admitted but never registered, other
     * than the returnees: the newly imported ones.
     *
     * @return list<int>
     */
    private function newlyImported(): array
    {
        return array_values(Learner::query()
            ->where('status', LearnerStatus::Active)
            ->whereDoesntHave('termRegistrations')
            ->whereHas('user', fn ($user) => $user->where('is_active', true)->where('email', 'not like', 'returnee.%'))
            ->orderBy('id')
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * Users of one registered first year per college (not a demo login)
     * who hasn't signed in yet.
     *
     * @return list<int>
     */
    private function firstYearsNotYetIn(): array
    {
        $users = [];

        foreach (College::orderBy('id')->get() as $college) {
            $candidates = array_values(Learner::query()
                ->where('year_level', 1)
                ->whereHas('termRegistrations')
                ->whereHas('user', fn ($user) => $user->where('college_id', $college->id)->where('is_active', true)->where('email', 'not like', '%.'.strtolower($college->code).'@%'))
                ->orderBy('id')
                ->pluck('user_id')
                ->all());

            if ($candidates !== []) {
                $users[] = (int) FilipinoNames::pick($candidates);
            }
        }

        return $users;
    }
}

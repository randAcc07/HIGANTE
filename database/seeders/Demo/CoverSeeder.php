<?php

namespace Database\Seeders\Demo;

use App\Enums\DutyStatus;
use App\Models\College;
use App\Models\Teacher;
use App\Models\Term;
use App\Models\User;
use App\Scheduling\Covers;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Substitutes covering an absent teacher's classes, arranged by the dean
 * through the app's own App\Scheduling\Covers (which tells each substitute),
 * a day or two ahead. One per college: COS's covers today and the next two
 * days, COE's last Wednesday and Thursday (AttendanceSeeder credits those
 * marks to the substitute), COEng's next Monday and Tuesday.
 *
 * The absent teacher is the college's non-demo teacher with the most
 * classes in the range; each class goes to a teacher of the college with
 * nothing then, the teacher.<college>@ demo login first.
 */
class CoverSeeder extends Seeder
{
    public function run(): void
    {
        $term = CampusData::currentTerm();
        $today = CarbonImmutable::today();
        $lastWeek = $today->startOfWeek()->subWeek()->addDays(2);
        $nextWeek = $today->startOfWeek()->addWeek();

        $ranges = [
            'COS' => [$today, $today->addDays(2), $today->subDay()->setTime(16, 0)],
            'COE' => [$lastWeek, $lastWeek->addDay(), $lastWeek->subDays(2)->setTime(15, 0)],
            'COEng' => [$nextWeek, $nextWeek->addDay(), $today->subDay()->setTime(10, 0)],
        ];

        foreach (College::orderBy('id')->get() as $college) {
            [$from, $to, $arrangedAt] = $ranges[$college->code];

            if ($from->lt($term->starts_on) || $to->gt($term->ends_on)) {
                continue;
            }

            $this->cover($college, $term, $from, $to, $arrangedAt->min(CarbonImmutable::now()));
        }
    }

    private function cover(College $college, Term $term, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $arrangedAt): void
    {
        $covers = app(Covers::class);
        $demo = StaffSeeder::teacherEmail($college->code);
        $teachers = Teacher::with('user')
            ->where('duty_status', DutyStatus::Active)
            ->whereHas('user', fn ($user) => $user->where('college_id', $college->id)->where('is_active', true))
            ->orderBy('id')
            ->get();

        // The demo teacher substitutes first; anyone else may be the one away.
        $substitutes = $teachers->sortBy(fn (Teacher $teacher): array => [$teacher->user->email !== $demo, $teacher->id])->values();
        $absent = $teachers
            ->reject(fn (Teacher $teacher): bool => $teacher->user->email === $demo)
            ->sortByDesc(fn (Teacher $teacher): int => count($covers->meetings($teacher, $term, $from, $to)))
            ->first();

        if ($absent === null) {
            return;
        }

        $plans = [];

        foreach ($covers->meetings($absent, $term, $from, $to) as ['schedule' => $schedule]) {
            $substitute = $substitutes->first(fn (Teacher $teacher): bool => $teacher->id !== $absent->id
                && $covers->clashes($schedule, $teacher, $from, $to, $plans) === []);

            if ($substitute !== null) {
                $plans[] = ['schedule' => $schedule, 'substitute' => $substitute];
            }
        }

        if ($plans !== []) {
            $dean = User::where('email', StaffSeeder::deanEmail($college->code))->sole();
            Clock::at($arrangedAt, fn () => $covers->arrange($plans, $from, $to, $dean));
        }
    }
}

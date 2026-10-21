<?php

namespace Database\Seeders\Demo;

use App\Attendance\Excuses;
use App\Enums\DecisionStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExcuseReason;
use App\Models\AbsenceExcuse;
use App\Models\AbsenceExcuseDocument;
use App\Models\College;
use App\Models\ExcuseDecision;
use App\Models\Learner;
use App\Models\Schedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * A handful of absence excuses per college, filed and decided class by
 * class through the app's own App\Attendance\Excuses, as of when it
 * happened: submit() opens a decision per class meeting in the range and
 * tells each class's teacher (the dean when it has none); decide() and
 * revoke() tell the learner.
 *
 * Per college: the learner.<college>@ demo login's medical excuse approved
 * in every class; a pending medical excuse with its certificate and a
 * pending illness excuse without one; one declined in every class; a
 * school activity approved in all but one class (partial); one filed by a
 * teacher, approved in that teacher's own classes and pending in the rest;
 * and one approved and then revoked in one class.
 *
 * Runs before attendance, which marks these learners absent on those days
 * and links the absences in the approved classes. Medical certificates are
 * placeholder PDFs under DOCUMENT_DIRECTORY, a folder shared with any other
 * demo database.
 */
class ExcuseSeeder extends Seeder
{
    public const DOCUMENT_DIRECTORY = 'absence-excuses/demo';

    /**
     * `decide`: approve | decline | partial (decline the last class) |
     * revoke (approve, then revoke the first class) | none.
     * `by`: learner, or teacher (a teacher of a class that day files it).
     * `classes`: the fewest classes the first day must have (default 1).
     *
     * @var list<array{reason: ExcuseReason, days: int, weeksAgo: int, remarks: string, decide: string, by: string, note?: string, classes?: int}>
     */
    private const PLANS = [
        ['reason' => ExcuseReason::Medical, 'days' => 2, 'weeksAgo' => 4, 'remarks' => 'Dengue fever; confined at home per the attached certificate.', 'decide' => 'approve', 'by' => 'learner'],
        ['reason' => ExcuseReason::Medical, 'days' => 1, 'weeksAgo' => 1, 'remarks' => 'Tooth extraction; medical certificate attached.', 'decide' => 'none', 'by' => 'learner'],
        ['reason' => ExcuseReason::Illness, 'days' => 1, 'weeksAgo' => 1, 'remarks' => 'Had a high fever but did not see a doctor.', 'decide' => 'none', 'by' => 'learner'],
        ['reason' => ExcuseReason::Other, 'days' => 1, 'weeksAgo' => 3, 'remarks' => 'Attended a family gathering in the province.', 'decide' => 'decline', 'by' => 'learner', 'note' => 'Not an excusable absence under the student handbook.'],
        ['reason' => ExcuseReason::SchoolActivity, 'days' => 1, 'weeksAgo' => 2, 'remarks' => 'Represented the college in the regional quiz bee.', 'decide' => 'partial', 'by' => 'learner', 'note' => 'The quiz bee ended at noon; this class met in the afternoon.', 'classes' => 2],
        ['reason' => ExcuseReason::SchoolActivity, 'days' => 1, 'weeksAgo' => 2, 'remarks' => 'Served as a student marshal at the college seminar.', 'decide' => 'none', 'by' => 'teacher'],
        ['reason' => ExcuseReason::Illness, 'days' => 1, 'weeksAgo' => 3, 'remarks' => 'Stomach flu; stayed home.', 'decide' => 'revoke', 'by' => 'learner', 'note' => 'Seen playing at the intramurals that afternoon.', 'classes' => 2],
    ];

    public function run(): void
    {
        Storage::disk('local')->deleteDirectory(self::DOCUMENT_DIRECTORY);

        $lastClassDay = AttendanceSeeder::lastClassDay();

        foreach (College::all() as $college) {
            $suspended = AttendanceSeeder::suspendedDates($college->id);

            // The demo learner gets the approved medical excuse; the rest go
            // to regular learners who are not demo logins.
            $learners = Learner::query()
                ->whereHas('user', fn ($user) => $user->where('college_id', $college->id)->where('email', 'not like', '%.'.strtolower($college->code).'@%'))
                ->whereHas('termRegistrations', fn ($registration) => $registration->whereNotNull('section_id')->where('is_irregular', false))
                ->whereDoesntHave('enrollments', fn ($enrollment) => $enrollment->where('status', EnrollmentStatus::Dropped))
                ->inRandomOrder(mt_rand())
                ->limit(count(self::PLANS))
                ->get()
                ->all();
            $learners[0] = Learner::whereHas('user', fn ($user) => $user->where('email', RegistrationSeeder::demoEmail('learner', $college->code)))->sole();

            foreach (self::PLANS as $index => $plan) {
                $from = $this->classDay($lastClassDay->subWeeks($plan['weeksAgo']), $suspended, self::weekdaysWith($learners[$index], $plan['classes'] ?? 1));

                if ($from === null || $from->lt(CampusData::date(CampusData::TERM['starts_on']))) {
                    continue;
                }

                $this->excuse($learners[$index], $plan, $from, $from->addDays($plan['days'] - 1));
            }
        }
    }

    /**
     * @param  array{reason: ExcuseReason, days: int, weeksAgo: int, remarks: string, decide: string, by: string, note?: string, classes?: int}  $plan
     */
    private function excuse(Learner $learner, array $plan, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $submittedAt = $to->addDay()->setTime(mt_rand(7, 17), mt_rand(0, 59))->min(CarbonImmutable::now());
        $filer = $plan['by'] === 'teacher' ? $this->teacherOn($learner, $from) : $learner->user()->sole();
        $data = ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString(), 'reason' => $plan['reason']->value, 'remarks' => $plan['remarks']];

        $excuse = Clock::at($submittedAt, function () use ($learner, $data, $filer, $plan, $from, $submittedAt): AbsenceExcuse {
            // The certificate is attached as a demo file rather than an
            // upload, so it lands in the demo folder.
            $excuse = app(Excuses::class)->submit($learner, $data, [], $filer);

            if ($plan['reason'] === ExcuseReason::Medical) {
                $this->attachCertificate($excuse, $learner, $from, $submittedAt);
            }

            return $excuse;
        });

        $decisions = $excuse->decisions()->where('status', DecisionStatus::Pending)->orderBy('id')->get()->values();
        $decidedAt = $submittedAt->addDay()->setTime(mt_rand(8, 11), mt_rand(0, 59))->min(CarbonImmutable::now());

        foreach ($decisions as $index => $decision) {
            $approve = match ($plan['decide']) {
                'approve', 'revoke' => true,
                'decline' => false,
                'partial' => $index < $decisions->count() - 1 || $decisions->count() === 1,
                default => null,
            };

            if ($approve === null) {
                continue;
            }

            $at = $decidedAt->addMinutes($index * mt_rand(5, 90))->min(CarbonImmutable::now());
            Clock::at($at, fn () => app(Excuses::class)->decide($decision, $this->decider($decision), $approve, $approve ? null : $plan['note'] ?? null));
        }

        if ($plan['decide'] === 'revoke' && isset($decisions[0])) {
            $decision = $decisions[0]->refresh();
            $at = ($decision->decided_at ?? $decidedAt)->addDays(2)->setTime(14, mt_rand(0, 59))->min(CarbonImmutable::now());
            Clock::at($at, fn () => app(Excuses::class)->revoke($decision, $this->decider($decision), $plan['note'] ?? ''));
        }
    }

    private function attachCertificate(AbsenceExcuse $excuse, Learner $learner, CarbonImmutable $from, CarbonImmutable $at): void
    {
        $path = self::DOCUMENT_DIRECTORY."/{$excuse->id}/medical-certificate.pdf";
        $pdf = DemoPdf::make(['Medical certificate - demo data', 'Patient: '.$learner->user()->sole()->full_name, 'Seen on '.$from->toFormattedDateString()]);
        Storage::disk('local')->put($path, $pdf);

        $document = new AbsenceExcuseDocument;
        $document->forceFill([
            'absence_excuse_id' => $excuse->id,
            'path' => $path,
            'original_name' => 'medical-certificate.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($pdf),
            'uploaded_by' => $learner->user_id,
            'created_at' => $at,
            'updated_at' => $at,
        ])->save();
    }

    /**
     * Whoever the app lets decide for the class: its teacher, or the dean
     * when it has none.
     */
    private function decider(ExcuseDecision $decision): User
    {
        return app(Excuses::class)->deciders($decision)->sortBy('id')->first()
            ?? throw new RuntimeException("Nobody can decide excuse decision {$decision->id}.");
    }

    /**
     * An active teacher of one of the learner's classes that day (lecture or
     * the learner's batch), who files the excuse for them.
     */
    private function teacherOn(Learner $learner, CarbonImmutable $day): User
    {
        $teacherIds = Schedule::query()
            ->join('enrollments', 'enrollments.subject_offering_id', '=', 'schedules.subject_offering_id')
            ->where('enrollments.learner_id', $learner->id)
            ->where(fn ($query) => $query->whereNull('schedules.batch_id')->orWhereColumn('schedules.batch_id', 'enrollments.batch_id'))
            ->whereRaw('WEEKDAY(schedules.starts_at) = ?', [$day->dayOfWeekIso - 1])
            ->orderBy('schedules.starts_at')
            ->pluck('schedules.teacher_id');

        return User::whereHas('teacher', fn ($teacher) => $teacher->whereIn('id', $teacherIds))->where('is_active', true)->orderBy('id')->first()
            ?? throw new RuntimeException("Learner {$learner->id} has no active teacher on {$day->toDateString()}.");
    }

    /**
     * The ISO weekdays on which the learner has at least that many classes
     * (lecture or their batch's meetings, counted per subject).
     *
     * @return list<int>
     */
    private static function weekdaysWith(Learner $learner, int $classes): array
    {
        return array_values(Schedule::query()
            ->join('enrollments', 'enrollments.subject_offering_id', '=', 'schedules.subject_offering_id')
            ->where('enrollments.learner_id', $learner->id)
            ->where(fn ($query) => $query->whereNull('schedules.batch_id')->orWhereColumn('schedules.batch_id', 'enrollments.batch_id'))
            ->selectRaw('WEEKDAY(schedules.starts_at) + 1 AS weekday')
            ->groupBy('weekday')
            ->havingRaw('COUNT(DISTINCT schedules.subject_offering_id) >= ?', [$classes])
            ->pluck('weekday')
            ->map(fn (mixed $weekday): int => (int) $weekday)
            ->all());
    }

    /**
     * The first day on or after the date on which the learner has classes,
     * with no suspension that day or the next.
     *
     * @param  array<string, true>  $suspended
     * @param  list<int>  $weekdays
     */
    private function classDay(CarbonImmutable $date, array $suspended, array $weekdays): ?CarbonImmutable
    {
        for ($day = $date; $day->lte(AttendanceSeeder::lastClassDay()); $day = $day->addDay()) {
            if (in_array($day->dayOfWeekIso, $weekdays, true) && ! isset($suspended[$day->toDateString()]) && ! isset($suspended[$day->addDay()->toDateString()])) {
                return $day;
            }
        }

        return null;
    }
}

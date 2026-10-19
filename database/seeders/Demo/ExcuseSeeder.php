<?php

namespace Database\Seeders\Demo;

use App\Enums\EnrollmentStatus;
use App\Enums\ExcuseReason;
use App\Enums\ExcuseStatus;
use App\Models\AbsenceExcuse;
use App\Models\AbsenceExcuseDocument;
use App\Models\College;
use App\Models\Learner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * A handful of absence excuses per college, in every status: approved and
 * pending medical certificates (with a file), a pending illness excuse
 * without one, a rejected excuse and an approved school activity. Runs
 * before attendance, which marks these learners absent on those days and
 * links the approved excuses to the absences.
 */
class ExcuseSeeder extends Seeder
{
    public const DOCUMENT_DIRECTORY = 'absence-excuses/demo';

    /**
     * @var list<array{reason: ExcuseReason, status: ExcuseStatus, days: int, weeksAgo: int, remarks: string, review?: string}>
     */
    private const PLANS = [
        ['reason' => ExcuseReason::Medical, 'status' => ExcuseStatus::Approved, 'days' => 2, 'weeksAgo' => 4, 'remarks' => 'Dengue fever; confined at home per the attached certificate.', 'review' => 'Certificate verified with the clinic.'],
        ['reason' => ExcuseReason::Medical, 'status' => ExcuseStatus::Pending, 'days' => 1, 'weeksAgo' => 1, 'remarks' => 'Tooth extraction; medical certificate attached.'],
        ['reason' => ExcuseReason::Illness, 'status' => ExcuseStatus::Pending, 'days' => 1, 'weeksAgo' => 1, 'remarks' => 'Had a high fever but did not see a doctor.'],
        ['reason' => ExcuseReason::Other, 'status' => ExcuseStatus::Rejected, 'days' => 1, 'weeksAgo' => 3, 'remarks' => 'Attended a family gathering in the province.', 'review' => 'Not an excusable absence under the student handbook.'],
        ['reason' => ExcuseReason::SchoolActivity, 'status' => ExcuseStatus::Approved, 'days' => 1, 'weeksAgo' => 2, 'remarks' => 'Represented the college in the regional quiz bee.', 'review' => 'Confirmed with the activity adviser.'],
    ];

    public function run(): void
    {
        Storage::disk('local')->deleteDirectory(self::DOCUMENT_DIRECTORY);

        $lastClassDay = AttendanceSeeder::lastClassDay();

        foreach (College::all() as $college) {
            $dean = User::where('email', StaffSeeder::deanEmail($college->code))->sole();
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
                $from = $this->classDay($lastClassDay->subWeeks($plan['weeksAgo']), $suspended, AttendanceSeeder::weekdaysOf($learners[$index]));

                if ($from === null || $from->lt(CarbonImmutable::parse(CampusData::TERM['starts_on']))) {
                    continue;
                }

                $this->excuse($learners[$index], $dean, $plan, $from, $from->addDays($plan['days'] - 1));
            }
        }
    }

    /**
     * @param  array{reason: ExcuseReason, status: ExcuseStatus, days: int, weeksAgo: int, remarks: string, review?: string}  $plan
     */
    private function excuse(Learner $learner, User $dean, array $plan, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $submittedAt = $to->addDay()->setTime(mt_rand(7, 17), mt_rand(0, 59));
        $reviewed = $plan['status'] !== ExcuseStatus::Pending;

        $excuse = new AbsenceExcuse;
        $excuse->forceFill([
            'learner_id' => $learner->id,
            'date_from' => $from,
            'date_to' => $to,
            'reason' => $plan['reason'],
            'remarks' => $plan['remarks'],
            'status' => $plan['status'],
            'submitted_by' => $learner->user_id,
            'reviewed_by' => $reviewed ? $dean->id : null,
            'reviewed_at' => $reviewed ? $submittedAt->addDay()->setTime(10, 0) : null,
            'review_remarks' => $reviewed ? $plan['review'] ?? null : null,
            'created_at' => $submittedAt,
            'updated_at' => $submittedAt,
        ])->save();

        if ($plan['reason'] === ExcuseReason::Medical) {
            $path = self::DOCUMENT_DIRECTORY."/{$excuse->id}/medical-certificate.pdf";
            $pdf = self::certificate($learner->user()->sole()->full_name, $from);
            Storage::disk('local')->put($path, $pdf);

            $document = new AbsenceExcuseDocument;
            $document->forceFill([
                'absence_excuse_id' => $excuse->id,
                'path' => $path,
                'original_name' => 'medical-certificate.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($pdf),
                'uploaded_by' => $learner->user_id,
                'created_at' => $submittedAt,
                'updated_at' => $submittedAt,
            ])->save();
        }
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

    /**
     * A one-page placeholder PDF.
     */
    private static function certificate(string $name, CarbonImmutable $date): string
    {
        $text = sprintf('BT /F1 14 Tf 72 720 Td (Medical certificate - demo data) Tj 0 -24 Td (Patient: %s) Tj 0 -24 Td (Seen on %s) Tj ET', $name, $date->toFormattedDateString());
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($text)." >>\nstream\n{$text}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref
0 '.(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}

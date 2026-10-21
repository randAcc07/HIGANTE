<?php

namespace Database\Seeders\Demo;

use App\Models\AttendancePolicy;
use App\Models\CalendarEvent;
use App\Models\College;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Last school year's two terms, the current term and the upcoming one, each
 * with the handbook attendance policy,
 * and the current term's holidays and college events (one suspending only an
 * afternoon, with its memo; one narrowed to a program's year level). Runs after the staff,
 * since the admin and deans are the events' authors.
 */
class TermSeeder extends Seeder
{
    public const MEMO_DIRECTORY = 'calendar-memos/demo';

    public function run(): void
    {
        foreach ([...CampusData::PAST_TERMS, CampusData::TERM, CampusData::NEXT_TERM] as $term) {
            $model = Term::create($term);
            AttendancePolicy::create(['term_id' => $model->id, ...AttendancePolicy::HANDBOOK]);
        }

        $admin = User::where('email', 'admin@'.CampusData::EMAIL_DOMAIN)->sole();
        $colleges = College::pluck('id', 'code');

        foreach (CampusData::CALENDAR as $event) {
            $collegeId = $event['college'] === null ? null : $colleges[$event['college']];
            $author = $collegeId === null
                ? $admin
                : User::where('email', StaffSeeder::deanEmail($event['college']))->sole();

            $model = new CalendarEvent([
                'college_id' => $collegeId,
                'title' => $event['title'],
                'description' => $event['description'] ?? null,
                'starts_on' => $event['starts_on'],
                'ends_on' => $event['ends_on'],
                'suspends_classes' => $event['suspends_classes'],
                'suspends_from' => $event['suspends_from'] ?? null,
                'year_levels' => $event['year_levels'] ?? null,
                'memo_number' => $event['memo_number'] ?? null,
                'created_by' => $author->id,
            ]);

            if (isset($event['entered_at'])) {
                $model->created_at = CarbonImmutable::parse($event['entered_at']);
                $model->updated_at = $model->created_at;
            }

            if (isset($event['memo_number'])) {
                $this->attachMemo($model, $event['memo_number'], $event['title'], $event['starts_on']);
            }

            $model->save();
            $model->programs()->sync(Program::whereIn('code', $event['programs'] ?? [])->pluck('id'));
        }
    }

    /**
     * A placeholder memo PDF on the local disk. The directory is shared with
     * any other demo database (dev and test); files are only ever overwritten.
     */
    private function attachMemo(CalendarEvent $event, string $number, string $title, string $date): void
    {
        $name = Str::slug($number).'.pdf';
        $path = self::MEMO_DIRECTORY.'/'.$name;

        Storage::disk('local')->put($path, DemoPdf::make([$number.' - demo data', $title, 'Classes from '.$event->suspends_from.' suspended on '.CarbonImmutable::parse($date)->toFormattedDateString()]));
        $event->forceFill(['memo_path' => $path, 'memo_name' => $name]);
    }
}

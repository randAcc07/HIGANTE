<?php

namespace Database\Seeders\Demo;

use App\Models\AttendancePolicy;
use App\Models\CalendarEvent;
use App\Models\College;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The previous and current terms, each with the handbook attendance policy,
 * and the current term's holidays and college events. Runs after the staff,
 * since the admin and deans are the events' authors.
 */
class TermSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([CampusData::PREVIOUS_TERM, CampusData::TERM] as $term) {
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

            CalendarEvent::create([
                'college_id' => $collegeId,
                'title' => $event['title'],
                'description' => $event['description'] ?? null,
                'starts_on' => $event['starts_on'],
                'ends_on' => $event['ends_on'],
                'suspends_classes' => $event['suspends_classes'],
                'created_by' => $author->id,
            ]);
        }
    }
}

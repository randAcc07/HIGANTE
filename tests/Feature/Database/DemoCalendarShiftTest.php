<?php

namespace Tests\Feature\Database;

use App\Enums\TermPhase;
use App\Models\AttendanceRecord;
use App\Models\CalendarEvent;
use App\Models\ClassCover;
use App\Models\Learner;
use App\Models\Term;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\CampusData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The demo calendar is written for CampusData::ANCHOR's week and moves by
 * whole weeks to the week it is seeded in.
 */
class DemoCalendarShiftTest extends TestCase
{
    use RefreshDatabase;

    public function test_dates_stay_as_written_in_the_anchor_week()
    {
        $this->travelTo('2026-10-25 20:00');

        $this->assertSame(0, CampusData::weeksShifted());
        $this->assertSame(CampusData::TERM, CampusData::term(CampusData::TERM));
    }

    public function test_dates_move_by_whole_weeks_keeping_their_weekday()
    {
        $this->travelTo('2026-11-04 09:00');

        $this->assertSame(2, CampusData::weeksShifted());
        $this->assertSame(['school_year' => '2026-2027', 'semester' => 'first', 'starts_on' => '2026-08-24', 'ends_on' => '2027-01-01'], CampusData::term(CampusData::TERM));
        $this->assertTrue(CampusData::date('2026-10-09 15:30')->eq(CarbonImmutable::parse('2026-10-23 15:30')));

        $this->travelTo('2026-07-01 09:00');

        $this->assertSame(-16, CampusData::weeksShifted());
        $this->assertSame('2026-2027', CampusData::term(CampusData::TERM)['school_year']);
        $this->assertTrue(CampusData::date(CampusData::TERM['starts_on'])->isMonday());
    }

    public function test_school_years_follow_together_once_moved_half_a_year_or_more()
    {
        $this->travelTo('2027-10-20 09:00');

        $this->assertSame(52, CampusData::weeksShifted());
        $this->assertSame('2026-2027', CampusData::term(CampusData::PAST_TERMS[1])['school_year']);
        $this->assertSame('2027-2028', CampusData::term(CampusData::TERM)['school_year']);
        $this->assertSame('2027-2028', CampusData::term(CampusData::NEXT_TERM)['school_year']);
    }

    /**
     * A year after the anchor, the demo reads the same relative to today:
     * a term in session with marks up to yesterday, a cover running today,
     * and learners' numbers following the moved school year.
     */
    public function test_the_demo_seeds_a_year_later()
    {
        Storage::fake('local');
        $this->travelTo('2027-10-20 08:00');
        $this->seed(DatabaseSeeder::class);

        $current = CampusData::currentTerm();
        $this->assertSame('2027-2028', $current->school_year);
        $this->assertSame(TermPhase::InSession, $current->phase());
        $this->assertSame(TermPhase::Upcoming, CampusData::nextTerm()->phase());
        $this->assertSame(['2026-2027', '2026-2027', '2027-2028', '2027-2028'], Term::orderBy('starts_on')->pluck('school_year')->all());

        $this->assertSame('2027-10-19', AttendanceRecord::max('class_date'));
        $this->assertTrue(ClassCover::where('starts_on', '<=', '2027-10-20')->where('ends_on', '>=', '2027-10-20')->exists());
        $this->assertTrue(CalendarEvent::whereNotNull('suspends_from')->sole()->starts_on->isFriday());
        $this->assertTrue(Learner::where('year_level', 1)->where('student_number', 'like', 'M2027-%')->exists());
    }
}

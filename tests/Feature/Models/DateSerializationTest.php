<?php

namespace Tests\Feature\Models;

use App\Models\AbsenceExcuse;
use App\Models\AttendanceRecord;
use App\Models\CalendarEvent;
use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Date-only columns must reach the frontend as plain dates. A default
 * `date` cast serializes as a UTC timestamp, which in Manila (UTC+8) puts
 * August 10 at "2026-08-09T16:00:00Z", a day early.
 */
class DateSerializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_date_only_columns_serialize_as_plain_dates()
    {
        $term = Term::factory()->create(['starts_on' => '2026-08-10', 'ends_on' => '2026-12-18']);
        $this->assertSame('2026-08-10', $term->toArray()['starts_on']);
        $this->assertSame('2026-12-18', $term->toArray()['ends_on']);

        $event = CalendarEvent::factory()->create(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-02']);
        $this->assertSame('2026-11-01', $event->toArray()['starts_on']);

        $excuse = AbsenceExcuse::factory()->create(['date_from' => '2026-09-07', 'date_to' => '2026-09-08']);
        $this->assertSame('2026-09-08', $excuse->toArray()['date_to']);

        $record = AttendanceRecord::factory()->create();
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $record->toArray()['class_date']);
    }
}

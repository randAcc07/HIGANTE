<?php

namespace Tests\Unit;

use App\Models\Schedule;
use App\Support\ReferenceWeek;
use InvalidArgumentException;
use Tests\TestCase;

class ReferenceWeekTest extends TestCase
{
    public function test_weekdays_map_onto_the_reference_week()
    {
        $this->assertSame('2001-01-01 08:30:00', ReferenceWeek::at(1, '08:30')->format('Y-m-d H:i:s'));
        $this->assertSame('2001-01-06 19:00:00', ReferenceWeek::at(6, '19:00')->format('Y-m-d H:i:s'));
        $this->assertSame('2001-01-07 07:00:00', ReferenceWeek::at(7, '07:00')->format('Y-m-d H:i:s'));
        $this->assertTrue(ReferenceWeek::at(1, '00:00')->isMonday());
    }

    public function test_weekdays_outside_1_to_7_are_rejected()
    {
        $this->expectException(InvalidArgumentException::class);

        ReferenceWeek::at(0, '08:00');
    }

    public function test_a_schedule_reads_back_its_weekday()
    {
        $schedule = (new Schedule)->setSlot(3, '13:00', '14:30');

        $this->assertSame(3, $schedule->weekday());
        $this->assertSame('13:00', $schedule->starts_at->format('H:i'));
        $this->assertSame('14:30', $schedule->ends_at->format('H:i'));
    }
}

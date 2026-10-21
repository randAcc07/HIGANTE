<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_minute_with_nothing_due_doesnt_touch_the_database()
    {
        config(['cache.default' => 'database']); // as on the server; tests use the array store
        $this->travelTo('2026-10-21 13:37');
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertSame([], DB::getQueryLog(), 'A database that sleeps when idle would be woken every minute.');
    }
}

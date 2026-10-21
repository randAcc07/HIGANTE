<?php

namespace Tests\Feature\Dean;

use App\Models\ClassCover;
use App\Models\College;
use App\Models\Enrollment;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\CoverNotice;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The teacher has CS 101 on Mondays 8:00–9:30 and Wednesdays 10:00–11:00;
 * the other teacher has a Monday 9:00 class of their own. The term runs
 * Aug 10 – Dec 18, 2026.
 */
class CoverTest extends SchedulingTestCase
{
    private Schedule $monday;

    private Schedule $wednesday;

    private Teacher $substitute;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->monday = Schedule::factory()->for($this->offering)->slot(1, '08:00', '09:30')->create(['teacher_id' => $this->teacher->id, 'room_id' => $this->room->id]);
        $this->wednesday = Schedule::factory()->for($this->offering)->slot(3, '10:00', '11:00')->create(['teacher_id' => $this->teacher->id, 'room_id' => $this->room->id]);
        Schedule::factory()->for($this->offering)->slot(1, '09:00', '10:00')->create(['teacher_id' => $this->otherTeacher->id, 'room_id' => $this->otherRoom->id]);
        $this->substitute = Teacher::factory()->forCollege($this->college)->create();
    }

    /**
     * @param  array<int, int|null>  $substitutes  schedule id => substitute id
     */
    private function arrange(array $substitutes, string $from = '2026-08-17', string $to = '2026-08-28'): TestResponse
    {
        return $this->post(route('dean.covers.store'), [
            'term_id' => $this->term->id,
            'teacher_id' => $this->teacher->id,
            'starts_on' => $from,
            'ends_on' => $to,
            'covers' => array_map(fn (int $schedule, ?int $substitute): array => ['schedule_id' => $schedule, 'substitute_id' => $substitute], array_keys($substitutes), $substitutes),
        ]);
    }

    public function test_the_dean_sees_the_teachers_classes_meeting_on_those_days()
    {
        $this->get(route('dean.covers.index', ['term' => $this->term->id, 'teacher' => $this->teacher->id, 'from' => '2026-08-17', 'to' => '2026-08-28']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('dean/covers/Index')
                ->missing('plan')
                ->reloadOnly('plan', fn (Assert $reload) => $reload
                    ->has('plan', 2)
                    ->where('plan.0.schedule_id', $this->monday->id)
                    ->where('plan.0.dates', ['2026-08-17', '2026-08-24'])
                    ->where('plan.1.dates', ['2026-08-19', '2026-08-26'])));
    }

    public function test_cover_is_arranged_and_the_substitute_told()
    {
        $this->arrange([$this->monday->id => $this->substitute->id, $this->wednesday->id => null])->assertSessionHasNoErrors();

        $cover = ClassCover::sole();
        $this->assertSame([$this->monday->id, $this->substitute->id, '2026-08-17', '2026-08-28'], [$cover->schedule_id, $cover->substitute_id, $cover->starts_on->toDateString(), $cover->ends_on->toDateString()]);
        $this->assertSame($this->teacher->id, $this->monday->fresh()?->teacher_id, 'The class keeps its teacher.');
        Notification::assertSentTo($this->substitute->user, CoverNotice::class, fn (CoverNotice $notice) => str_contains($notice->message, 'CS 101 (BSCS 1-A, Mon 8:00–9:30 AM)') && str_contains($notice->message, 'Aug 17–28'));
    }

    public function test_a_substitute_is_never_double_booked()
    {
        // Their own Monday 9:00 class overlaps.
        $this->arrange([$this->monday->id => $this->otherTeacher->id])
            ->assertSessionHasErrors(['covers.0.substitute_id']);
        $this->assertStringContainsString('teaches BSCS 1-A · CS 101 Mon 9:00–10:00 AM', session('errors')->first('covers.0.substitute_id'));

        // Nor over a class they already cover then.
        $this->arrange([$this->monday->id => $this->substitute->id])->assertSessionHasNoErrors();
        $this->arrange([$this->monday->id => $this->substitute->id], '2026-08-24', '2026-09-04')
            ->assertSessionHasErrors(['covers.0.substitute_id']);

        // A class's own teacher can't cover it.
        $this->arrange([$this->wednesday->id => $this->teacher->id])
            ->assertSessionHasErrors(['covers.0.substitute_id' => "{$this->teacher->user->full_name} already teaches this class."]);
        $this->assertSame(1, ClassCover::count());
    }

    public function test_the_substitute_marks_only_the_days_they_cover_and_the_teacher_still_can()
    {
        $enrollment = Enrollment::factory()->for($this->offering)->create();
        $enrollment->forceFill(['created_at' => '2026-08-01'])->save();
        $this->arrange([$this->monday->id => $this->substitute->id])->assertSessionHasNoErrors();
        $this->travelTo('2026-09-02 10:00');

        $this->actingAs($this->substitute->user);
        $this->get(route('teacher.classes.index', ['date' => '2026-08-24']))->assertInertia(fn (Assert $page) => $page
            ->has('classes', 1)
            ->where('classes.0.covering', true)
            ->where('classes.0.teacher', $this->teacher->user->full_name));
        $this->get(route('attendance.show', ['schedule' => $this->monday, 'date' => '2026-08-24']))->assertInertia(fn (Assert $page) => $page
            ->where('canEdit', true)
            ->where('covering', true)
            ->where('cover.range', 'Aug 17–28')
            ->where('previous', '2026-08-17')
            ->where('next', null));
        $this->put(route('attendance.update', ['schedule' => $this->monday, 'date' => '2026-08-24']), ['marks' => [['enrollment_id' => $enrollment->id, 'status' => 'absent']]])
            ->assertSessionHasNoErrors();

        $this->get(route('attendance.show', ['schedule' => $this->monday, 'date' => '2026-08-31']))->assertForbidden();
        $this->get(route('attendance.show', ['schedule' => $this->wednesday, 'date' => '2026-08-26']))->assertForbidden();

        $this->actingAs($this->teacher->user);
        $this->get(route('attendance.show', ['schedule' => $this->monday, 'date' => '2026-08-24']))->assertInertia(fn (Assert $page) => $page
            ->where('canEdit', true)
            ->where('covering', false)
            ->where('cover.substitute', $this->substitute->user->full_name));
        $this->get(route('teacher.classes.index', ['date' => '2026-08-24']))
            ->assertInertia(fn (Assert $page) => $page->where('classes.0.cover', $this->substitute->user->full_name));
    }

    public function test_removing_cover_ends_the_substitutes_access()
    {
        $this->arrange([$this->monday->id => $this->substitute->id])->assertSessionHasNoErrors();
        $cover = ClassCover::sole();

        $this->delete(route('dean.covers.destroy', $cover))->assertSessionHasNoErrors();

        $this->assertModelMissing($cover);
        Notification::assertSentTo($this->substitute->user, CoverNotice::class, fn (CoverNotice $notice) => $notice->kind === CoverNotice::REMOVED);
        $this->travelTo('2026-08-18 10:00');
        $this->actingAs($this->substitute->user)->get(route('attendance.show', ['schedule' => $this->monday, 'date' => '2026-08-17']))->assertForbidden();
    }

    public function test_only_the_colleges_dean_arranges_cover()
    {
        $this->actingAs(User::factory()->dean(College::factory()->create())->create());

        $this->arrange([$this->monday->id => $this->substitute->id])->assertSessionHasErrors('teacher_id');
        $this->assertSame(0, ClassCover::count());

        $this->actingAs($this->dean);
        $this->arrange([$this->monday->id => $this->substitute->id]);
        $this->actingAs(User::factory()->dean(College::factory()->create())->create());
        $this->delete(route('dean.covers.destroy', ClassCover::sole()))->assertForbidden();
    }
}

<?php

namespace App\Scheduling;

use App\Attendance\ClassDays;
use App\Models\ClassCover;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Models\Term;
use App\Models\User;
use App\Notifications\CoverNotice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Substitutes covering a teacher's classes for a range of dates. The class
 * keeps its teacher; the substitute may also see and mark its meetings in
 * the range. A substitute can't be double-booked: not over their own
 * classes, nor over another class they cover then.
 *
 * Inject per action (ClassDays caches suspensions).
 */
final class Covers
{
    public function __construct(private readonly ClassDays $days) {}

    /**
     * The teacher's classes in the term that meet in the range, each with
     * its meeting dates there.
     *
     * @return list<array{schedule: Schedule, dates: list<CarbonImmutable>}>
     */
    public function meetings(Teacher $teacher, Term $term, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $schedules = Schedule::query()
            ->with(['subjectOffering.subject', 'subjectOffering.section.program', 'batch', 'room', 'term'])
            ->where('term_id', $term->id)
            ->where('teacher_id', $teacher->id)
            ->orderBy('starts_at')
            ->get();
        $meetings = [];

        foreach ($schedules as $schedule) {
            $dates = $this->dates($schedule, $from, $to);

            if ($dates !== []) {
                $meetings[] = ['schedule' => $schedule, 'dates' => $dates];
            }
        }

        return $meetings;
    }

    /**
     * The class's meeting dates in the range (its weekday, in its term, not
     * suspended).
     *
     * @return list<CarbonImmutable>
     */
    public function dates(Schedule $schedule, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dates = [];
        $day = $from->addDays(($schedule->weekday() - $from->dayOfWeekIso + 7) % 7);

        for (; $day->lte($to); $day = $day->addWeek()) {
            if ($this->days->meets($schedule, $day)) {
                $dates[] = $day;
            }
        }

        return $dates;
    }

    /**
     * Why the substitute can't cover the class in the range, in words, or
     * nothing. $planned are the other covers being arranged with it.
     *
     * @param  list<array{schedule: Schedule, substitute: Teacher}>  $planned
     * @return list<string>
     */
    public function clashes(Schedule $schedule, Teacher $substitute, CarbonImmutable $from, CarbonImmutable $to, array $planned = []): array
    {
        $substitute->loadMissing('user');
        $name = $substitute->user->full_name;
        $messages = [];

        if ($schedule->teacher_id === $substitute->id) {
            return [__(':name already teaches this class.', ['name' => $name])];
        }

        $taken = ClassCover::with('substitute.user')
            ->where('schedule_id', $schedule->id)
            ->where('starts_on', '<=', $to->toDateString())
            ->where('ends_on', '>=', $from->toDateString())
            ->first();

        if ($taken !== null) {
            $messages[] = __('Already covered by :name on :range.', ['name' => $taken->substitute->user->full_name, 'range' => $taken->range()]);
        }

        $overlapping = fn (Schedule $other): bool => $other->id !== $schedule->id
            && $other->starts_at->lt($schedule->ends_at)
            && $other->ends_at->gt($schedule->starts_at);

        // Their own classes then, that meet in the range.
        $own = Schedule::with(['subjectOffering.subject', 'subjectOffering.section.program', 'batch', 'term'])
            ->where('term_id', $schedule->term_id)
            ->where('teacher_id', $substitute->id)
            ->get()
            ->filter($overlapping);

        foreach ($own as $other) {
            if ($this->dates($other, $from, $to) !== []) {
                $messages[] = __(':name teaches :what :when.', ['name' => $name, 'what' => ScheduleConflicts::describe($other, $schedule), 'when' => ScheduleConflicts::when($other)]);
            }
        }

        // Classes they already cover, or are being given now, then.
        $covered = ClassCover::with(['schedule.subjectOffering.subject', 'schedule.subjectOffering.section.program', 'schedule.batch'])
            ->where('substitute_id', $substitute->id)
            ->where('starts_on', '<=', $to->toDateString())
            ->where('ends_on', '>=', $from->toDateString())
            ->get()
            ->map(fn (ClassCover $cover): Schedule => $cover->schedule);
        $alongside = collect($planned)
            ->filter(fn (array $plan): bool => $plan['substitute']->id === $substitute->id)
            ->map(fn (array $plan): Schedule => $plan['schedule']);

        foreach ($covered->concat($alongside)->filter($overlapping)->unique('id') as $other) {
            $messages[] = __(':name covers :what :when then.', ['name' => $name, 'what' => ScheduleConflicts::describe($other, $schedule), 'when' => ScheduleConflicts::when($other)]);
        }

        return $messages;
    }

    /**
     * Save the covers (already checked) and tell each substitute.
     *
     * @param  list<array{schedule: Schedule, substitute: Teacher}>  $plans
     * @return Collection<int, ClassCover>
     */
    public function arrange(array $plans, CarbonImmutable $from, CarbonImmutable $to, User $dean): Collection
    {
        $covers = DB::transaction(fn (): Collection => new Collection(array_map(fn (array $plan): ClassCover => ClassCover::create([
            'schedule_id' => $plan['schedule']->id,
            'substitute_id' => $plan['substitute']->id,
            'starts_on' => $from->toDateString(),
            'ends_on' => $to->toDateString(),
            'created_by' => $dean->id,
        ]), $plans)));

        $covers->load(['schedule.subjectOffering.subject', 'schedule.subjectOffering.section.program', 'schedule.teacher.user', 'substitute.user']);

        foreach ($covers->groupBy('substitute_id') as $theirs) {
            $theirs->first()?->substitute->user->notify(CoverNotice::assigned(array_values($theirs->all())));
        }

        return $covers;
    }

    /**
     * The cover of the class on that date, if any.
     */
    public static function on(Schedule $schedule, CarbonImmutable $date): ?ClassCover
    {
        return ClassCover::with('substitute.user')->where('schedule_id', $schedule->id)->onDate($date)->first();
    }
}

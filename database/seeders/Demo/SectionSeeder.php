<?php

namespace Database\Seeders\Demo;

use App\Enums\DutyStatus;
use App\Enums\RoomType;
use App\Enums\ScheduleMode;
use App\Models\Batch;
use App\Models\College;
use App\Models\Program;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Section;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\Teacher;
use App\Models\Term;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The current term's sections, their subject offerings, lab batches and a
 * conflict-free weekly timetable: no section, teacher or room is ever in two
 * places at once, and no class runs over the 12:00–13:00 lunch break.
 *
 * Lab subjects get two batches named after the section (1-A → 1-AA, 1-AB),
 * each with its own lab period; the lecture stays with the whole section.
 * Batch membership is decided when learners are enrolled.
 *
 * @phpstan-type Part array{day: int, start: int, end: int}
 * @phpstan-type Meeting array{kind: string, batch: int|null, rooms: list<int>, online: bool}
 * @phpstan-type Placement array{meeting: Meeting, parts: list<Part>, room: int|null}
 */
class SectionSeeder extends Seeder
{
    private const LUNCH = [12 * 60, 13 * 60];

    private const LETTERS = 'ABCDEFGH';

    /**
     * Busy periods per resource ("s12", "t4", "r9") and ISO weekday.
     *
     * @var array<string, array<int, list<array{int, int}>>>
     */
    private array $busy = [];

    /** @var array<int, int> Weekly minutes per teacher id. */
    private array $teacherMinutes = [];

    /** @var array<int, list<int>> Teacher ids already teaching each subject id. */
    private array $subjectTeachers = [];

    /** @var array<string, list<list<Part>>> Candidate slots per meeting kind. */
    private array $slots = [];

    public function run(): void
    {
        $term = Term::where('school_year', CampusData::TERM['school_year'])
            ->where('semester', CampusData::TERM['semester'])
            ->sole();

        $sharedRooms = self::ids(Room::where('building', 'Main Building')->where('is_active', true));
        $peRooms = self::ids(Room::whereIn('code', ['GYM', 'COURT']));

        foreach (College::with('programs')->get() as $college) {
            $data = CampusData::COLLEGES[$college->code];
            $teachers = self::ids(Teacher::query()
                ->where('duty_status', DutyStatus::Active)
                ->whereHas('user', fn ($user) => $user->where('college_id', $college->id)->where('is_active', true))
                ->orderBy('id'));
            $lectureRooms = self::ids(Room::where('building', $data['building'])->where('room_type', RoomType::Lecture));
            $labRooms = array_map(fn (array $rooms): array => self::ids(Room::whereIn('code', array_keys($rooms))), $data['labs']);
            $subjects = Subject::where('college_id', $college->id)->get()->keyBy('code');

            foreach ($college->programs as $program) {
                foreach (CampusData::YEAR_LEVELS as $yearLevel => $year) {
                    $overflowing = $yearLevel === 1 && in_array($program->code, CampusData::OVERFLOWING_PROGRAMS, true);

                    for ($i = 0; $i < $year['sections']; $i++) {
                        $section = Section::create([
                            'term_id' => $term->id,
                            'program_id' => $program->id,
                            'year_level' => $yearLevel,
                            'name' => $yearLevel.'-'.self::LETTERS[$i],
                            'capacity' => $overflowing ? 30 : $year['capacity'],
                        ]);

                        $this->timetable($term, $section, $this->curriculum($program, $yearLevel, $subjects), $teachers, [
                            'lecture' => $lectureRooms,
                            'shared' => $sharedRooms,
                            'pe' => $peRooms,
                            'labs' => $labRooms,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * The section's subjects, with their timetabling options, hardest to
     * place first (labs, then fixed-day NSTP, then the rest).
     *
     * @param  Collection<string, Subject>  $subjects
     * @return list<array{subject: Subject, options: array{kind?: string, lab?: string, tba?: bool}}>
     */
    private function curriculum(Program $program, int $yearLevel, Collection $subjects): array
    {
        $rows = [];

        foreach (CampusData::GENERAL_EDUCATION as $subject) {
            if (in_array($subject[0], CampusData::GENERAL_EDUCATION_BY_YEAR[$yearLevel], true)) {
                $rows[] = self::row($subjects, $subject);
            }
        }

        $college = $program->college()->sole();

        foreach (CampusData::PROGRAMS[$college->code][$program->code]['years'][$yearLevel] as $subject) {
            $rows[] = self::row($subjects, $subject);
        }

        usort($rows, fn (array $a, array $b): int => self::difficulty($b['options']) <=> self::difficulty($a['options']));

        return $rows;
    }

    /**
     * @param  Collection<string, Subject>  $subjects
     * @param  array{0: string, 1: string, 2: int, 3: int, 4?: array{kind?: string, lab?: string, tba?: bool}}  $subject
     * @return array{subject: Subject, options: array{kind?: string, lab?: string, tba?: bool}}
     */
    private static function row(Collection $subjects, array $subject): array
    {
        return [
            'subject' => $subjects->get($subject[0]) ?? throw new RuntimeException("Subject {$subject[0]} was not seeded."),
            'options' => $subject[4] ?? [],
        ];
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @return list<int>
     */
    private static function ids(Builder $query): array
    {
        return array_values(array_map(intval(...), $query->pluck('id')->all()));
    }

    /**
     * @param  array{kind?: string, lab?: string, tba?: bool}  $options
     */
    private static function difficulty(array $options): int
    {
        return isset($options['lab']) ? 2 : (($options['kind'] ?? null) === 'nstp' ? 1 : 0);
    }

    /**
     * @param  list<array{subject: Subject, options: array{kind?: string, lab?: string, tba?: bool}}>  $curriculum
     * @param  list<int>  $teachers
     * @param  array{lecture: list<int>, shared: list<int>, pe: list<int>, labs: array<string, list<int>>}  $rooms
     */
    private function timetable(Term $term, Section $section, array $curriculum, array $teachers, array $rooms): void
    {
        foreach ($curriculum as ['subject' => $subject, 'options' => $options]) {
            $offering = SubjectOffering::create(['section_id' => $section->id, 'subject_id' => $subject->id]);

            if ($options['tba'] ?? false) {
                continue;
            }

            $batchIds = [];

            if ((float) $subject->laboratory_units > 0) {
                foreach (['A', 'B'] as $letter) {
                    $batchIds[] = Batch::create(['subject_offering_id' => $offering->id, 'name' => $section->name.$letter])->id;
                }
            }

            $meetings = $this->meetings($subject, $options, $rooms, $batchIds);
            [$teacherId, $placements] = $this->place($section, $subject, $meetings, $teachers);

            $offering->teacher_id = $teacherId;
            $offering->save();

            foreach ($placements as $placement) {
                foreach ($placement['parts'] as $part) {
                    $schedule = new Schedule([
                        'subject_offering_id' => $offering->id,
                        'batch_id' => $placement['meeting']['batch'],
                        'term_id' => $term->id,
                        'teacher_id' => $teacherId,
                        'room_id' => $placement['room'],
                        'mode' => $placement['meeting']['online'] ? ScheduleMode::Online : ScheduleMode::Onsite,
                    ]);
                    $schedule->setSlot($part['day'], self::time($part['start']), self::time($part['end']))->save();
                }
            }
        }
    }

    /**
     * What a subject needs each week.
     *
     * @param  array{kind?: string, lab?: string, tba?: bool}  $options
     * @param  array{lecture: list<int>, shared: list<int>, pe: list<int>, labs: array<string, list<int>>}  $rooms
     * @param  list<int>  $batches  Batch ids, each getting its own lab period.
     * @return list<Meeting>
     */
    private function meetings(Subject $subject, array $options, array $rooms, array $batches): array
    {
        $kind = $options['kind'] ?? null;
        $generalEducation = str_starts_with($subject->code, 'GE ');
        $lectureRooms = $generalEducation ? $rooms['shared'] : [...$rooms['lecture'], ...$rooms['shared']];

        $meetings = match (true) {
            $kind === 'nstp' => [['kind' => 'saturday180', 'batch' => null, 'rooms' => $rooms['shared'], 'online' => false]],
            $kind === 'pe' => [['kind' => 'single120', 'batch' => null, 'rooms' => $rooms['pe'], 'online' => false]],
            $kind === 'project' => [['kind' => 'single180', 'batch' => null, 'rooms' => [], 'online' => true]],
            (float) $subject->lecture_units >= 3 => [['kind' => 'pair90', 'batch' => null, 'rooms' => $lectureRooms, 'online' => false]],
            (float) $subject->lecture_units > 0 => [['kind' => 'single120', 'batch' => null, 'rooms' => $lectureRooms, 'online' => false]],
            default => [],
        };

        foreach ($batches as $batch) {
            $meetings[] = ['kind' => 'single180', 'batch' => $batch, 'rooms' => $rooms['labs'][$options['lab'] ?? ''], 'online' => false];
        }

        return $meetings;
    }

    /**
     * Picks one teacher for the whole offering and a slot and room for each
     * meeting. Teachers who already teach the subject are tried first, then
     * the least loaded.
     *
     * @param  list<Meeting>  $meetings
     * @param  list<int>  $teachers
     * @return array{int, list<Placement>}
     */
    private function place(Section $section, Subject $subject, array $meetings, array $teachers): array
    {
        $minutes = 0;

        foreach ($meetings as $meeting) {
            foreach ($this->slots($meeting['kind'])[0] as $part) {
                $minutes += $part['end'] - $part['start'];
            }
        }

        $candidates = $teachers;
        usort($candidates, fn (int $a, int $b): int => [
            ! in_array($a, $this->subjectTeachers[$subject->id] ?? [], true), $this->teacherMinutes[$a] ?? 0, $a,
        ] <=> [
            ! in_array($b, $this->subjectTeachers[$subject->id] ?? [], true), $this->teacherMinutes[$b] ?? 0, $b,
        ]);

        foreach ($candidates as $teacherId) {
            if (($this->teacherMinutes[$teacherId] ?? 0) + $minutes > CampusData::MAX_TEACHER_HOURS * 60) {
                continue;
            }

            $placements = $this->tryTeacher("s{$section->id}", "t{$teacherId}", $meetings);

            if ($placements === null) {
                continue;
            }

            foreach ($placements as $placement) {
                foreach ($placement['parts'] as $part) {
                    foreach (array_filter(["s{$section->id}", "t{$teacherId}", $placement['room'] === null ? null : "r{$placement['room']}"]) as $key) {
                        $this->busy[$key][$part['day']][] = [$part['start'], $part['end']];
                    }
                }
            }

            $this->teacherMinutes[$teacherId] = ($this->teacherMinutes[$teacherId] ?? 0) + $minutes;
            $this->subjectTeachers[$subject->id][] = $teacherId;

            return [$teacherId, $placements];
        }

        throw new RuntimeException("No teacher, slot and room left for {$subject->code} in section {$section->name} (id {$section->id}).");
    }

    /**
     * @param  list<Meeting>  $meetings
     * @return list<Placement>|null
     */
    private function tryTeacher(string $sectionKey, string $teacherKey, array $meetings): ?array
    {
        $placements = [];
        /** @var array<string, array<int, list<array{int, int}>>> $taken */
        $taken = [];

        foreach ($meetings as $meeting) {
            $placement = null;

            foreach ($this->slots($meeting['kind']) as $parts) {
                if (! $this->free($sectionKey, $parts, $taken) || ! $this->free($teacherKey, $parts, $taken)) {
                    continue;
                }

                if ($meeting['online']) {
                    $placement = ['meeting' => $meeting, 'parts' => $parts, 'room' => null];
                    break;
                }

                $rooms = $meeting['rooms'];
                shuffle($rooms);

                foreach ($rooms as $room) {
                    if ($this->free("r{$room}", $parts, $taken)) {
                        $placement = ['meeting' => $meeting, 'parts' => $parts, 'room' => $room];
                        break 2;
                    }
                }
            }

            if ($placement === null) {
                return null;
            }

            foreach ($placement['parts'] as $part) {
                foreach (array_filter([$sectionKey, $teacherKey, $placement['room'] === null ? null : "r{$placement['room']}"]) as $key) {
                    $taken[$key][$part['day']][] = [$part['start'], $part['end']];
                }
            }

            $placements[] = $placement;
        }

        return $placements;
    }

    /**
     * @param  list<Part>  $parts
     * @param  array<string, array<int, list<array{int, int}>>>  $taken
     */
    private function free(string $key, array $parts, array $taken): bool
    {
        foreach ($parts as $part) {
            foreach ([...$this->busy[$key][$part['day']] ?? [], ...$taken[$key][$part['day']] ?? []] as [$start, $end]) {
                if ($part['start'] < $end && $start < $part['end']) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Candidate slots for a meeting kind, in a random order (weekdays before
     * Saturday). The same seed gives the same order.
     *
     * @return non-empty-list<list<Part>>
     */
    private function slots(string $kind): array
    {
        if (! isset($this->slots[$kind])) {
            $this->slots[$kind] = match ($kind) {
                'pair90' => self::pairs([[1, 3], [2, 4], [1, 4], [2, 5], [3, 5]], 90, ['07:30', '09:00', '10:30', '13:00', '14:30', '16:00', '17:30']),
                'single120' => self::singles([1, 2, 3, 4, 5, 6], 120),
                'single180' => self::singles([1, 2, 3, 4, 5, 6], 180),
                'saturday180' => self::singles([6], 180),
                default => throw new RuntimeException("Unknown meeting kind {$kind}."),
            };
        }

        $slots = $this->slots[$kind];
        $weekdays = array_values(array_filter($slots, fn (array $parts): bool => $parts[0]['day'] < 6));
        $saturdays = array_values(array_filter($slots, fn (array $parts): bool => $parts[0]['day'] === 6));
        shuffle($weekdays);
        shuffle($saturdays);

        /** @var non-empty-list<list<Part>> */
        return [...$weekdays, ...$saturdays];
    }

    /**
     * @param  list<array{int, int}>  $dayPairs
     * @param  list<string>  $starts
     * @return non-empty-list<list<Part>>
     */
    private static function pairs(array $dayPairs, int $length, array $starts): array
    {
        $slots = [];

        foreach ($dayPairs as $days) {
            foreach ($starts as $start) {
                $minute = self::minutes($start);
                $slots[] = array_map(fn (int $day): array => ['day' => $day, 'start' => $minute, 'end' => $minute + $length], $days);
            }
        }

        /** @var non-empty-list<list<Part>> */
        return $slots;
    }

    /**
     * Every whole-hour start from 07:00 that ends by 20:00 and misses lunch.
     *
     * @param  list<int>  $days
     * @return non-empty-list<list<Part>>
     */
    private static function singles(array $days, int $length): array
    {
        $slots = [];

        foreach ($days as $day) {
            for ($start = 7 * 60; $start + $length <= 20 * 60; $start += 60) {
                if ($start < self::LUNCH[1] && self::LUNCH[0] < $start + $length) {
                    continue;
                }

                $slots[] = [['day' => $day, 'start' => $start, 'end' => $start + $length]];
            }
        }

        /** @var non-empty-list<list<Part>> */
        return $slots;
    }

    private static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map(intval(...), explode(':', $time));

        return $hours * 60 + $minutes;
    }

    private static function time(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}

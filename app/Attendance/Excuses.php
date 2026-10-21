<?php

namespace App\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\DecisionStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExcuseStatus;
use App\Enums\UserRole;
use App\Models\AbsenceExcuse;
use App\Models\AttendanceAudit;
use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\ExcuseDecision;
use App\Models\Learner;
use App\Models\Schedule;
use App\Models\Term;
use App\Models\User;
use App\Notifications\AttendanceNotice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Absence excuses, decided class by class. On submission each class the
 * learner has a meeting in during the range gets a decision, which a
 * teacher of that class (whoever teaches it now) approves or declines; the
 * dean of the college decides only for a class without a teacher, and
 * otherwise oversees. An approval excuses the learner's absences in that
 * class on the excuse's days (lates stay), including absences marked
 * later; revoking it restores them. Each link and unlink is audited and
 * the standing recounted. A medical excuse needs a document to be
 * approved. Nothing touching a closed term's days is decided or revoked.
 * A teacher can file an excuse for a learner, which counts as approved in
 * their own classes.
 *
 * Resolve it per request: it uses Standing and ClassDays, which cache.
 */
class Excuses
{
    public function __construct(private Standing $standing, private ClassDays $days) {}

    /**
     * @param  array{date_from: string, date_to: string, reason: string, remarks?: string|null}  $data
     * @param  list<UploadedFile>  $files
     */
    public function submit(Learner $learner, array $data, array $files, User $by): AbsenceExcuse
    {
        $excuse = DB::transaction(function () use ($learner, $data, $files, $by): AbsenceExcuse {
            $excuse = AbsenceExcuse::create([
                'learner_id' => $learner->id,
                'date_from' => $data['date_from'],
                'date_to' => $data['date_to'],
                'reason' => $data['reason'],
                'remarks' => $data['remarks'] ?? null,
                'status' => ExcuseStatus::Pending,
                'submitted_by' => $by->id,
            ]);

            foreach ($files as $file) {
                $excuse->documents()->create([
                    'path' => $file->store("absence-excuses/{$excuse->id}"),
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'size_bytes' => $file->getSize(),
                    'uploaded_by' => $by->id,
                ]);
            }

            foreach ($this->classesMeeting($excuse) as $offeringId) {
                $excuse->decisions()->create(['subject_offering_id' => $offeringId, 'status' => DecisionStatus::Pending]);
            }

            return $excuse;
        });

        // Filed by a teacher: it is approved in their own classes.
        if ($by->role === UserRole::Teacher) {
            foreach ($excuse->decisions as $decision) {
                if ($this->canDecide($by, $decision) && ! $this->needsDocument($excuse)) {
                    $this->decide($decision, $by, true, null, notify: false);
                }
            }
        }

        foreach ($excuse->decisions()->where('status', DecisionStatus::Pending)->get() as $decision) {
            foreach ($this->deciders($decision) as $decider) {
                $decider->notify(AttendanceNotice::excuseToDecide($decision));
            }
        }

        $this->summarize($excuse);

        return $excuse;
    }

    /**
     * Approve or decline the excuse for one class.
     */
    public function decide(ExcuseDecision $decision, User $by, bool $approve, ?string $remarks, bool $notify = true): void
    {
        $excuse = $decision->absenceExcuse;
        $this->refuseUnless($decision, DecisionStatus::Pending);

        if ($approve && $this->needsDocument($excuse)) {
            throw ValidationException::withMessages(['excuse' => __('A medical excuse needs its certificate attached before it can be approved.')]);
        }

        $enrollments = DB::transaction(function () use ($decision, $by, $approve, $remarks, $excuse): array {
            $decision->update([
                'status' => $approve ? DecisionStatus::Approved : DecisionStatus::Declined,
                'remarks' => $remarks,
                'decided_by' => $by->id,
                'decided_at' => now(),
            ]);

            if (! $approve) {
                return [];
            }

            $records = $this->absences($excuse, $decision->subject_offering_id)->whereNull('absence_excuse_id')->get();

            foreach ($records as $record) {
                $this->link($record, $excuse->id, $by);
            }

            return $records->pluck('enrollment_id')->unique()->values()->all();
        });

        $this->standing->refreshMany($enrollments);
        $this->summarize($excuse);

        if ($notify) {
            $excuse->learner->user->notify($approve ? AttendanceNotice::excuseApproved($decision) : AttendanceNotice::excuseDeclined($decision));
        }
    }

    /**
     * Withdraw one class's approval: its absences count again, unless
     * another approved excuse covers the day in that class.
     */
    public function revoke(ExcuseDecision $decision, User $by, string $remarks): void
    {
        $excuse = $decision->absenceExcuse;
        $this->refuseUnless($decision, DecisionStatus::Approved);

        $enrollments = DB::transaction(function () use ($decision, $by, $remarks, $excuse): array {
            $decision->update(['status' => DecisionStatus::Revoked, 'revoked_by' => $by->id, 'revoked_at' => now(), 'revoke_remarks' => $remarks]);

            $records = $this->absences($excuse, $decision->subject_offering_id)->where('absence_excuse_id', $excuse->id)->get();

            foreach ($records as $record) {
                $this->link($record, $this->covering($excuse->learner_id, $decision->subject_offering_id, $record->class_date)?->id, $by);
            }

            return $records->pluck('enrollment_id')->unique()->values()->all();
        });

        $this->standing->refreshMany($enrollments);
        $this->summarize($excuse);
        $excuse->learner->user->notify(AttendanceNotice::excuseRevoked($decision));
    }

    /**
     * Excuse an absence just marked on a day an excuse approved for that
     * class covers. Returns whether it was linked.
     */
    public function linkIfCovered(AttendanceRecord $record, User $by): bool
    {
        if ($record->status !== AttendanceStatus::Absent || $record->absence_excuse_id !== null) {
            return false;
        }

        $record->loadMissing('enrollment');
        $excuse = $this->covering($record->enrollment->learner_id, $record->enrollment->subject_offering_id, $record->class_date);

        if ($excuse === null) {
            return false;
        }

        $this->link($record, $excuse->id, $by);

        return true;
    }

    /**
     * Who may decide for this class now: whoever teaches the learner's
     * meetings of it (lecture or their batch), else its default teacher;
     * the college's deans when it has no active teacher (none assigned, or
     * the teacher's account was deactivated).
     *
     * @return Collection<int, User>
     */
    public function deciders(ExcuseDecision $decision): Collection
    {
        $decision->loadMissing(['absenceExcuse.learner.program', 'subjectOffering']);
        $teachers = User::whereHas('teacher', fn ($teacher) => $teacher->whereIn('id', $this->teacherIds($decision)))
            ->where('is_active', true)
            ->get();

        if ($teachers->isNotEmpty()) {
            return $teachers;
        }

        return User::where('role', UserRole::Dean)
            ->where('college_id', $decision->absenceExcuse->learner->program->college_id)
            ->where('is_active', true)
            ->get();
    }

    /**
     * The decisions a teacher may make now: classes where they teach the
     * learner's meetings (lecture or the learner's batch), or the default
     * teacher of a class with no meetings yet. The same rule as deciders().
     *
     * @return Builder<ExcuseDecision>
     */
    public static function decidableBy(int $teacherId): Builder
    {
        return ExcuseDecision::query()->where(fn ($query) => $query
            ->whereExists(fn ($meetings) => $meetings->selectRaw('1')
                ->from('schedules as s')
                ->join('enrollments as e', 'e.subject_offering_id', '=', 's.subject_offering_id')
                ->join('absence_excuses as x', 'x.learner_id', '=', 'e.learner_id')
                ->whereColumn('x.id', 'excuse_decisions.absence_excuse_id')
                ->whereColumn('s.subject_offering_id', 'excuse_decisions.subject_offering_id')
                ->where('s.teacher_id', $teacherId)
                ->where(fn ($batch) => $batch->whereNull('s.batch_id')->orWhereColumn('s.batch_id', 'e.batch_id')))
            ->orWhere(fn ($fallback) => $fallback
                ->whereExists(fn ($offering) => $offering->selectRaw('1')->from('subject_offerings as o')->whereColumn('o.id', 'excuse_decisions.subject_offering_id')->where('o.teacher_id', $teacherId))
                ->whereNotExists(fn ($meetings) => $meetings->selectRaw('1')->from('schedules as s')->whereColumn('s.subject_offering_id', 'excuse_decisions.subject_offering_id'))));
    }

    public function canDecide(User $user, ExcuseDecision $decision): bool
    {
        return $this->deciders($decision)->contains('id', $user->id);
    }

    /**
     * The learner's absences (not lates) on the excuse's days, in one class
     * or all.
     *
     * @return Builder<AttendanceRecord>
     */
    public function absences(AbsenceExcuse $excuse, ?int $offeringId = null): Builder
    {
        return AttendanceRecord::query()
            ->where('status', AttendanceStatus::Absent)
            ->whereBetween('class_date', [$excuse->date_from->toDateString(), $excuse->date_to->toDateString()])
            ->whereHas('enrollment', fn ($query) => $query
                ->where('learner_id', $excuse->learner_id)
                ->when($offeringId !== null, fn ($query) => $query->where('subject_offering_id', $offeringId)));
    }

    /**
     * Why nobody can decide on this excuse now, or null when they can.
     */
    public function lock(AbsenceExcuse $excuse): ?string
    {
        $closed = Term::whereNotNull('closed_at')
            ->where('starts_on', '<=', $excuse->date_to->toDateString())
            ->where('ends_on', '>=', $excuse->date_from->toDateString())
            ->first();

        if ($closed === null) {
            return null;
        }

        return __(':term is closed, so excuses covering its days can no longer be changed.', ['term' => $closed->label]);
    }

    /**
     * The excuse's status from its decisions: pending while any class is
     * undecided; otherwise approved, rejected (all declined), revoked
     * (every approval revoked) or partial.
     */
    public function summarize(AbsenceExcuse $excuse): void
    {
        $statuses = $excuse->decisions()->pluck('status')->map(fn (DecisionStatus|string $status): DecisionStatus => $status instanceof DecisionStatus ? $status : DecisionStatus::from($status));
        $has = fn (DecisionStatus $status): bool => $statuses->contains($status);

        $summary = match (true) {
            $statuses->isEmpty(), $has(DecisionStatus::Pending) => ExcuseStatus::Pending,
            $statuses->every(fn (DecisionStatus $status): bool => $status === DecisionStatus::Approved) => ExcuseStatus::Approved,
            $statuses->every(fn (DecisionStatus $status): bool => $status === DecisionStatus::Declined) => ExcuseStatus::Rejected,
            ! $has(DecisionStatus::Approved) && $has(DecisionStatus::Revoked) && ! $has(DecisionStatus::Declined) => ExcuseStatus::Revoked,
            default => ExcuseStatus::Partial,
        };

        if ($excuse->status !== $summary) {
            $excuse->update(['status' => $summary]);
        }
    }

    private function needsDocument(AbsenceExcuse $excuse): bool
    {
        return $excuse->reason->requiresDocument() && ! $excuse->documents()->exists();
    }

    /**
     * The learner's classes with at least one meeting in the excuse's range
     * (their batch's, not suspended, while they were enrolled).
     *
     * @return list<int>
     */
    private function classesMeeting(AbsenceExcuse $excuse): array
    {
        $from = $excuse->date_from;
        $to = $excuse->date_to;
        $offerings = [];

        $enrollments = Enrollment::query()
            ->where('learner_id', $excuse->learner_id)
            ->whereHas('subjectOffering.section.term', fn ($term) => $term->where('starts_on', '<=', $to->toDateString())->where('ends_on', '>=', $from->toDateString()))
            ->get();

        foreach ($enrollments as $enrollment) {
            $start = $from->max($enrollment->created_at?->startOfDay() ?? $from);
            $end = $enrollment->status === EnrollmentStatus::Dropped && $enrollment->drop_confirmed_at !== null
                ? $to->min($enrollment->drop_confirmed_at->startOfDay())
                : $to;

            $schedules = Schedule::with(['term', 'subjectOffering.section.program'])
                ->where('subject_offering_id', $enrollment->subject_offering_id)
                ->where(fn ($query) => $query->whereNull('batch_id')->orWhere('batch_id', $enrollment->batch_id))
                ->get();

            foreach ($schedules as $schedule) {
                for ($day = $start; $day->lte($end); $day = $day->addDay()) {
                    if ($this->days->meets($schedule, $day)) {
                        $offerings[$enrollment->subject_offering_id] = true;

                        continue 3;
                    }
                }
            }
        }

        return array_keys($offerings);
    }

    /**
     * Teachers of the learner's meetings of the class, or its default
     * teacher while it has no meetings at all (as decidableBy()).
     *
     * @return list<int>
     */
    private function teacherIds(ExcuseDecision $decision): array
    {
        $excuse = $decision->absenceExcuse;
        $batchId = Enrollment::where('learner_id', $excuse->learner_id)->where('subject_offering_id', $decision->subject_offering_id)->value('batch_id');

        $ids = Schedule::where('subject_offering_id', $decision->subject_offering_id)
            ->where(fn ($query) => $query->whereNull('batch_id')->orWhere('batch_id', $batchId))
            ->distinct()
            ->pluck('teacher_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        // No meetings scheduled yet at all: the class's default teacher.
        if ($ids === [] && $decision->subjectOffering->teacher_id !== null && Schedule::where('subject_offering_id', $decision->subject_offering_id)->doesntExist()) {
            $ids = [$decision->subjectOffering->teacher_id];
        }

        return array_values(array_unique($ids));
    }

    /**
     * An excuse of the learner covering that day and approved in that class.
     */
    private function covering(int $learnerId, int $offeringId, CarbonImmutable $day): ?AbsenceExcuse
    {
        return AbsenceExcuse::where('learner_id', $learnerId)
            ->where('date_from', '<=', $day->toDateString())
            ->where('date_to', '>=', $day->toDateString())
            ->whereHas('decisions', fn ($query) => $query->where('subject_offering_id', $offeringId)->where('status', DecisionStatus::Approved))
            ->orderBy('id')
            ->first();
    }

    /**
     * Point an absence at an excuse (or none), with an audit row.
     */
    private function link(AttendanceRecord $record, ?int $excuseId, User $by): void
    {
        if ($record->absence_excuse_id === $excuseId) {
            return;
        }

        $old = $record->absence_excuse_id;
        $record->update(['absence_excuse_id' => $excuseId]);

        AttendanceAudit::create([
            'attendance_record_id' => $record->id,
            'changed_by' => $by->id,
            'old_status' => $record->status,
            'new_status' => $record->status,
            'old_absence_excuse_id' => $old,
            'new_absence_excuse_id' => $excuseId,
            'changed_at' => now(),
        ]);
    }

    private function refuseUnless(ExcuseDecision $decision, DecisionStatus $status): void
    {
        $lock = $this->lock($decision->absenceExcuse);

        if ($lock !== null) {
            throw ValidationException::withMessages(['excuse' => $lock]);
        }

        if ($decision->status !== $status) {
            throw ValidationException::withMessages(['excuse' => __('This class was already decided. Reload the page.')]);
        }
    }
}

<?php

namespace App\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\ExcuseReason;
use App\Enums\ExcuseStatus;
use App\Enums\UserRole;
use App\Models\AbsenceExcuse;
use App\Models\AttendanceAudit;
use App\Models\AttendanceRecord;
use App\Models\Learner;
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
 * Absence excuses: the learner submits one for a date range, the dean of
 * their college approves, rejects or later revokes it. An approved excuse
 * excuses the learner's absences on its days in every class (lates stay
 * lates), including absences marked after the approval; revoking restores
 * them. Each link or unlink is audited and the standing recounted. Only a
 * medical excuse needs a document to be approved; for the others it is the
 * dean's call. Nothing touching a closed term's days is reviewed or revoked.
 *
 * Resolve it per request: it uses Standing, which caches suspensions.
 */
class Excuses
{
    public function __construct(private Standing $standing) {}

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

            return $excuse;
        });

        foreach (self::deans($learner) as $dean) {
            $dean->notify(AttendanceNotice::excuseSubmitted($excuse));
        }

        return $excuse;
    }

    public function approve(AbsenceExcuse $excuse, User $dean, ?string $remarks): void
    {
        $this->refuseUnless($excuse, ExcuseStatus::Pending);

        if ($excuse->reason->requiresDocument() && ! $excuse->documents()->exists()) {
            throw ValidationException::withMessages(['excuse' => __('A medical excuse needs its certificate attached before it can be approved.')]);
        }

        $enrollments = DB::transaction(function () use ($excuse, $dean, $remarks): array {
            $excuse->update(['status' => ExcuseStatus::Approved, 'reviewed_by' => $dean->id, 'reviewed_at' => now(), 'review_remarks' => $remarks]);

            $records = $this->absences($excuse)->whereNull('absence_excuse_id')->get();

            foreach ($records as $record) {
                $this->link($record, $excuse->id, $dean);
            }

            return $records->pluck('enrollment_id')->unique()->values()->all();
        });

        $this->standing->refreshMany($enrollments);
        $excuse->learner->user->notify(AttendanceNotice::excuseApproved($excuse));
    }

    public function reject(AbsenceExcuse $excuse, User $dean, string $remarks): void
    {
        $this->refuseUnless($excuse, ExcuseStatus::Pending);

        $excuse->update(['status' => ExcuseStatus::Rejected, 'reviewed_by' => $dean->id, 'reviewed_at' => now(), 'review_remarks' => $remarks]);
        $excuse->learner->user->notify(AttendanceNotice::excuseRejected($excuse));
    }

    /**
     * Withdraw an approval: its absences count again, unless another
     * approved excuse covers the same day (they move to that one).
     */
    public function revoke(AbsenceExcuse $excuse, User $dean, string $remarks): void
    {
        $this->refuseUnless($excuse, ExcuseStatus::Approved);

        $enrollments = DB::transaction(function () use ($excuse, $dean, $remarks): array {
            $excuse->update(['status' => ExcuseStatus::Revoked, 'revoked_at' => now(), 'revoked_by' => $dean->id, 'revoke_remarks' => $remarks]);

            $records = AttendanceRecord::with('enrollment')->where('absence_excuse_id', $excuse->id)->get();

            foreach ($records as $record) {
                $this->link($record, $this->covering($excuse->learner_id, $record->class_date)?->id, $dean);
            }

            return $records->pluck('enrollment_id')->unique()->values()->all();
        });

        $this->standing->refreshMany($enrollments);
        $excuse->learner->user->notify(AttendanceNotice::excuseRevoked($excuse));
    }

    /**
     * Excuse an absence just marked on a day an approved excuse covers.
     * Returns whether it was linked.
     */
    public function linkIfCovered(AttendanceRecord $record, User $by): bool
    {
        if ($record->status !== AttendanceStatus::Absent || $record->absence_excuse_id !== null) {
            return false;
        }

        $record->loadMissing('enrollment');
        $excuse = $this->covering($record->enrollment->learner_id, $record->class_date);

        if ($excuse === null) {
            return false;
        }

        $this->link($record, $excuse->id, $by);

        return true;
    }

    /**
     * The learner's absences (not lates) on the excuse's days, in any class.
     *
     * @return Builder<AttendanceRecord>
     */
    public function absences(AbsenceExcuse $excuse): Builder
    {
        return AttendanceRecord::query()
            ->where('status', AttendanceStatus::Absent)
            ->whereBetween('class_date', [$excuse->date_from->toDateString(), $excuse->date_to->toDateString()])
            ->whereHas('enrollment', fn ($query) => $query->where('learner_id', $excuse->learner_id));
    }

    /**
     * Why the dean can't decide on this excuse now, or null when they can.
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
     * An approved excuse of the learner covering that day, if any.
     */
    private function covering(int $learnerId, CarbonImmutable $day): ?AbsenceExcuse
    {
        return AbsenceExcuse::where('learner_id', $learnerId)
            ->where('status', ExcuseStatus::Approved)
            ->where('date_from', '<=', $day->toDateString())
            ->where('date_to', '>=', $day->toDateString())
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

    private function refuseUnless(AbsenceExcuse $excuse, ExcuseStatus $status): void
    {
        $lock = $this->lock($excuse);

        if ($lock !== null) {
            throw ValidationException::withMessages(['excuse' => $lock]);
        }

        if ($excuse->status !== $status) {
            throw ValidationException::withMessages(['excuse' => __('This excuse was already decided. Reload the page.')]);
        }
    }

    /**
     * @return Collection<int, User>
     */
    private static function deans(Learner $learner): Collection
    {
        $learner->loadMissing('program');

        return User::where('role', UserRole::Dean)
            ->where('college_id', $learner->program->college_id)
            ->where('is_active', true)
            ->get();
    }

    /**
     * @return list<string>
     */
    public static function reasons(): array
    {
        return array_map(fn (ExcuseReason $reason): string => $reason->value, ExcuseReason::cases());
    }
}

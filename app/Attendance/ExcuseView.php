<?php

namespace App\Attendance;

use App\Http\Controllers\ExcuseDocumentController;
use App\Models\AbsenceExcuse;
use App\Models\AbsenceExcuseDocument;
use App\Models\ExcuseDecision;

/**
 * How an excuse and its per-class decisions read on the learner's, the
 * teachers' and the dean's pages.
 */
final class ExcuseView
{
    /**
     * @return array<string, mixed>
     */
    public static function excuse(AbsenceExcuse $excuse): array
    {
        $excuse->loadMissing(['documents', 'decisions.subjectOffering.subject:id,code,title', 'decisions.subjectOffering.section.program:id,code', 'decisions.decider', 'submitter']);

        return [
            'id' => $excuse->id,
            'date_from' => $excuse->date_from->toDateString(),
            'date_to' => $excuse->date_to->toDateString(),
            'reason' => $excuse->reason->value,
            'remarks' => $excuse->remarks,
            'status' => $excuse->status->value,
            'submitted_on' => $excuse->created_at?->toDateString(),
            // Filed by a teacher (not the learner), by name.
            'filed_by' => $excuse->submitted_by !== null && $excuse->submitter?->id !== $excuse->learner->user_id ? $excuse->submitter?->full_name : null,
            'days_late' => $excuse->daysLate(),
            'documents' => $excuse->documents->map(fn (AbsenceExcuseDocument $document): array => [
                'id' => $document->id,
                'name' => $document->original_name,
                'size' => $document->size_bytes,
                'url' => action([ExcuseDocumentController::class, 'show'], $document, false),
            ])->values(),
            'decisions' => $excuse->decisions->map(fn (ExcuseDecision $decision): array => self::decision($decision))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function decision(ExcuseDecision $decision): array
    {
        $offering = $decision->subjectOffering;

        return [
            'id' => $decision->id,
            'subject' => $offering->subject->only(['code', 'title']),
            'section' => "{$offering->section->program->code} {$offering->section->name}",
            'status' => $decision->status->value,
            'remarks' => $decision->remarks,
            'decided_by' => $decision->decider?->full_name,
            'decided_on' => $decision->decided_at?->toDateString(),
            'revoke_remarks' => $decision->revoke_remarks,
            'revoked_on' => $decision->revoked_at?->toDateString(),
        ];
    }
}

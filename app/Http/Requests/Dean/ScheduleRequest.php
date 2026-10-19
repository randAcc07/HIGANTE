<?php

namespace App\Http\Requests\Dean;

use App\Enums\ScheduleMode;
use App\Http\Controllers\Dean\OfferingController;
use App\Models\Batch;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\SubjectOffering;
use App\Scheduling\ScheduleConflicts;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One weekly class: Mon–Sat, 07:00–20:00 in 30-minute steps, onsite in an
 * active room or online, taught by an active teacher of the college. Clashes
 * with rooms, teachers or the section are reported in words; a class that
 * already has attendance keeps its day and time.
 */
class ScheduleRequest extends FormRequest
{
    /** 07:00 to 20:00 on the hour or half hour; nothing after 20:00. */
    private const TIME = '/^((0[7-9]|1\d):(00|30)|20:00)$/';

    private ?Schedule $candidate = null;

    /**
     * Only the dean of the section's college, and never in a closed term.
     */
    public function authorize(): bool
    {
        return Gate::allows('update', $this->offering()->section);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $offering = $this->offering();
        $collegeId = $offering->section->program->college_id;

        return [
            'batch_id' => ['nullable', 'integer', Rule::exists(Batch::class, 'id')->where('subject_offering_id', $offering->id)],
            'weekday' => ['required', 'integer', 'between:1,6'],
            'start' => ['required', 'string', 'regex:'.self::TIME],
            'end' => ['required', 'string', 'regex:'.self::TIME, 'after:start'],
            'mode' => ['required', Rule::enum(ScheduleMode::class)],
            'room_id' => [Rule::requiredIf($this->input('mode') === ScheduleMode::Onsite->value), 'nullable', 'integer', Rule::exists(Room::class, 'id')->where('is_active', true)],
            'teacher_id' => ['required', 'integer', OfferingController::teacherRule($collegeId)],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $candidate = $this->candidate();

                // The candidate is the saved class with the new values filled
                // in, so "dirty" means the day or time is changing.
                if ($candidate->exists && $candidate->isDirty(['starts_at', 'ends_at'])
                    && $candidate->attendanceRecords()->exists()) {
                    $validator->errors()->add('start', __('This class already has attendance, so its day and time stay. Add a new class instead.'));

                    return;
                }

                // One key per conflict, since pages get only the first error
                // of each key: slot.0, slot.1, ...
                foreach (app(ScheduleConflicts::class)->conflicts($candidate) as $i => $conflict) {
                    $validator->errors()->add("slot.{$i}", $conflict);
                }
            },
        ];
    }

    /**
     * The class as it would be saved.
     */
    public function candidate(): Schedule
    {
        if ($this->candidate !== null) {
            return $this->candidate;
        }

        $existing = $this->route('schedule');
        $offering = $this->offering();
        $online = $this->input('mode') === ScheduleMode::Online->value;

        $candidate = $existing instanceof Schedule ? $existing : new Schedule;
        $candidate->fill([
            'subject_offering_id' => $offering->id,
            'batch_id' => $this->input('batch_id') ?: null,
            'term_id' => $offering->section->term_id,
            'teacher_id' => $this->integer('teacher_id'),
            'room_id' => $online ? null : $this->integer('room_id'),
            'mode' => $this->input('mode'),
        ]);
        $candidate->setSlot($this->integer('weekday'), $this->string('start')->value(), $this->string('end')->value());
        $candidate->setRelation('subjectOffering', $offering);

        return $this->candidate = $candidate;
    }

    public function offering(): SubjectOffering
    {
        $existing = $this->route('schedule');
        $offering = $existing instanceof Schedule ? $existing->subjectOffering : $this->route('offering');

        if (! $offering instanceof SubjectOffering) {
            abort(404);
        }

        return $offering->loadMissing('section.program', 'section.term');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start.regex' => __('Classes run from 7:00 AM to 8:00 PM, on the hour or half hour.'),
            'end.regex' => __('Classes run from 7:00 AM to 8:00 PM, on the hour or half hour.'),
            'end.after' => __('The class must end after it starts.'),
            'room_id.required' => __('Choose a room, or make the class online.'),
            'room_id.exists' => __('Choose an active room.'),
            'teacher_id.exists' => __('Choose an active teacher of your college.'),
        ];
    }
}

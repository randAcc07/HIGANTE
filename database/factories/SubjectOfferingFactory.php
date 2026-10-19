<?php

namespace Database\Factories;

use App\Models\College;
use App\Models\Section;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The subject and default teacher come from the section's college.
 *
 * @extends Factory<SubjectOffering>
 */
class SubjectOfferingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'section_id' => Section::factory(),
            'subject_id' => fn (array $attributes) => Subject::factory()->state([
                'college_id' => self::sectionCollege($attributes['section_id'])->id,
            ]),
            'teacher_id' => fn (array $attributes) => Teacher::factory()->forCollege(
                self::sectionCollege($attributes['section_id']),
            ),
        ];
    }

    protected static function sectionCollege(int $sectionId): College
    {
        return Section::with('program.college')->findOrFail($sectionId)->program->college;
    }
}

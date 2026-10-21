<?php

namespace Database\Factories;

use App\Enums\Semester;
use App\Models\CurriculumSubject;
use App\Models\Program;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The subject comes from the program's college.
 *
 * @extends Factory<CurriculumSubject>
 */
class CurriculumSubjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'program_id' => Program::factory(),
            'subject_id' => fn (array $attributes) => Subject::factory()->state([
                'college_id' => self::programCollegeId($attributes['program_id']),
            ]),
            'year_level' => 1,
            'semester' => Semester::First,
        ];
    }

    protected static function programCollegeId(int $programId): int
    {
        return Program::findOrFail($programId)->college_id;
    }
}

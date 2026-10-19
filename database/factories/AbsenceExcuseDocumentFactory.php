<?php

namespace Database\Factories;

use App\Models\AbsenceExcuse;
use App\Models\AbsenceExcuseDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AbsenceExcuseDocument>
 */
class AbsenceExcuseDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'absence_excuse_id' => AbsenceExcuse::factory(),
            'path' => 'excuses/'.fake()->uuid().'.pdf',
            'original_name' => 'medical-certificate.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(10_000, 2_000_000),
            'uploaded_by' => null,
        ];
    }
}

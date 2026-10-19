<?php

namespace Database\Factories;

use App\Models\EmailChange;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A fresh request that neither address has answered yet.
 *
 * @extends Factory<EmailChange>
 */
class EmailChangeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'new_email' => fake()->unique()->safeEmail(),
            'expires_at' => now()->addHours(EmailChange::LIFETIME_HOURS),
            'new_confirmed_at' => null,
            'old_approved_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }

    public function newConfirmed(): static
    {
        return $this->state(['new_confirmed_at' => now()]);
    }

    public function oldApproved(): static
    {
        return $this->state(['old_approved_at' => now()]);
    }
}

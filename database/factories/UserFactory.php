<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\College;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A plain user is an admin (campus-wide, no college). Use the role states
 * for everyone else; teachers and learners also need their profile row, so
 * prefer TeacherFactory and LearnerFactory for those.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role' => UserRole::Admin,
            'college_id' => null,
            'is_active' => true,
            'last_name' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->lastName(),
            'suffix' => null,
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => UserRole::Admin, 'college_id' => null]);
    }

    public function registrar(): static
    {
        return $this->state(['role' => UserRole::Registrar, 'college_id' => null]);
    }

    public function dean(?College $college = null): static
    {
        return $this->inCollege(UserRole::Dean, $college);
    }

    public function teacher(?College $college = null): static
    {
        return $this->inCollege(UserRole::Teacher, $college);
    }

    public function learner(?College $college = null): static
    {
        return $this->inCollege(UserRole::Learner, $college);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    protected function inCollege(UserRole $role, ?College $college): static
    {
        return $this->state([
            'role' => $role,
            'college_id' => $college ?? College::factory(),
        ]);
    }
}

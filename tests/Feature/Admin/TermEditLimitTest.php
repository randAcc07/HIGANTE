<?php

namespace Tests\Feature\Admin;

use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TermEditLimitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function termData(array $override = []): array
    {
        return [
            'school_year' => '2026-2027', 'semester' => 'first', 'starts_on' => '2026-08-10', 'ends_on' => '2026-12-18',
            'lates_per_absence' => 3, 'drop_at_absences' => 5, 'warn_at_absences' => [3, 4], ...$override,
        ];
    }

    public function test_the_admin_sets_or_clears_the_teachers_edit_limit()
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('admin.terms.store'), $this->termData(['teacher_edit_days' => 7]))->assertSessionHasNoErrors();
        $term = Term::sole();
        $this->assertSame(7, $term->attendancePolicy?->teacher_edit_days);

        $this->put(route('admin.terms.update', $term), $this->termData(['teacher_edit_days' => null]))->assertSessionHasNoErrors();
        $this->assertNull($term->attendancePolicy()->sole()->teacher_edit_days);

        $this->put(route('admin.terms.update', $term), $this->termData(['teacher_edit_days' => 0]))->assertSessionHasNoErrors();
        $this->assertSame(0, $term->attendancePolicy()->sole()->teacher_edit_days);

        $this->put(route('admin.terms.update', $term), $this->termData(['teacher_edit_days' => -1]))->assertSessionHasErrors('teacher_edit_days');
    }
}

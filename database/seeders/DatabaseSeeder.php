<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Demo\AttendanceSeeder;
use Database\Seeders\Demo\CatalogSeeder;
use Database\Seeders\Demo\ExcuseSeeder;
use Database\Seeders\Demo\FilipinoNames;
use Database\Seeders\Demo\NextTermSeeder;
use Database\Seeders\Demo\PastTermSeeder;
use Database\Seeders\Demo\RegistrationSeeder;
use Database\Seeders\Demo\SectionSeeder;
use Database\Seeders\Demo\StaffSeeder;
use Database\Seeders\Demo\StandingSeeder;
use Database\Seeders\Demo\TermSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demo data for local development: a three-college campus in the middle of
 * its first semester, with timetables, registrations, attendance, warnings,
 * drop flags and excuses; last school year's registrations for the record;
 * and an upcoming 2nd semester with sections but nobody registered yet. The
 * same seed gives the same data every run.
 *
 * Load it with `composer db:demo`, which rebuilds the schema as the migrate
 * user and then seeds as the app user. (`migrate:fresh --seed` cannot work:
 * it seeds through the migrate connection, which may not insert.)
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public const SEED = 20261019;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Refusing to load demo data in production.');
        }

        if (User::query()->exists()) {
            throw new RuntimeException('The database already has users. Run `composer db:demo` to rebuild it with demo data.');
        }

        mt_srand(self::SEED);
        fake()->seed(self::SEED);
        app()->instance(FilipinoNames::class, new FilipinoNames);

        DB::transaction(fn () => $this->call([
            CatalogSeeder::class,
            StaffSeeder::class,
            TermSeeder::class,
            SectionSeeder::class,
            NextTermSeeder::class,
            RegistrationSeeder::class,
            PastTermSeeder::class,
            ExcuseSeeder::class,
            AttendanceSeeder::class,
            StandingSeeder::class,
        ]));

        $this->command->newLine();
        $this->command->info('Demo logins (password: '.StaffSeeder::PASSWORD.')');
        $this->command->table(['Email', 'Role', 'College'], User::with('college')
            ->where('email', 'regexp', '^(admin|registrar|(dean|teacher|learner|atrisk|irregular|returnee)\\.[a-z]+)@')
            ->orderBy('id')
            ->get()
            ->map(fn (User $user): array => [$user->email, $user->role->label(), $user->college->code ?? '—']));
    }
}

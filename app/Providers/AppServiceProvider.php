<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Console\Output\ConsoleOutput;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->snapshotSchemaAfterMigrations();
    }

    /**
     * Keep db/higante_schema.sql in step with the migrations during local
     * development.
     */
    protected function snapshotSchemaAfterMigrations(): void
    {
        if (! $this->app->environment('local')) {
            return;
        }

        Event::listen(MigrationsEnded::class, function (MigrationsEnded $event): void {
            if (! ($event->options['pretend'] ?? false)) {
                Artisan::call('db:snapshot', outputBuffer: new ConsoleOutput);
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}

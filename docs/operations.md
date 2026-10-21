# Operations

How to set up the Scheduler for real use or for a demo, which database
account runs what, and the server commands for admin accounts.

## Two database accounts

`database/provisioning/provision.sh` (run once, piped into MariaDB as root)
creates the database and two accounts:

| Account                         | Can                                       | Used by                                      |
| ------------------------------- | ----------------------------------------- | -------------------------------------------- |
| app (`DB_USERNAME`)             | Read and write data; no schema changes    | The app, and every command that touches data |
| migrate (`DB_MIGRATE_USERNAME`) | Schema changes; data only in `migrations` | `--database=mariadb_migrate` commands        |

Commands follow what they touch:

- **Migrate account** (`--database=mariadb_migrate`): `migrate`,
  `migrate:status`, `migrate:rollback`; in development also `migrate:fresh`,
  `migrate:refresh`, `migrate:reset`. Without the flag they run as the app
  account, which has no schema rights, so a slip fails safely.
- **App account** (default connection): `app:create-admin` and the other
  `app:*` admin commands, `db:seed`, `model:prune`.
- **Either**: `db:snapshot` (reads the structure only; run it after schema
  changes to refresh `db/scheduler_schema.sql` and `.dbml`).

In production, `migrate:fresh`, `migrate:refresh`, `migrate:reset` and
`db:wipe` are refused (`DB::prohibitDestructiveCommands`), and the demo
seeder refuses to run.

## Setting up

Both start the same way: provision the database, then `composer setup`
(dependencies, app key, migrations as the migrate account, frontend build).

|           | Demo data                                                                                             | Real data                                                                                                                                                                    |
| --------- | ----------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Schema    | `composer db:demo` rebuilds it (`migrate:fresh`)                                                      | `php artisan migrate --database=mariadb_migrate --force` on every deploy; never fresh                                                                                        |
| Data      | `composer db:demo` seeds a three-college campus mid-semester; logins are printed, password `password` | Nothing is seeded. `php artisan app:create-admin` once; the admin then enters colleges, programs, subjects, rooms, terms and staff in the app; the registrar admits learners |
| Mail      | `MAIL_MAILER=log`: setup links and notices go to the log                                              | A real mailer, or hand setup links over from the copy-link dialog (or the links file after a sheet import)                                                                   |
| Scheduler | Optional                                                                                              | A cron entry running `php artisan schedule:run` every minute (daily: prunes expired email-change requests, and learner import uploads and setup-link files older than a day) |

Nothing is queued yet, so no queue worker is needed.

## Admin accounts

Admins are peers: no admin can create, change or deactivate another admin in
the app. Whoever runs the server does, with these commands (run as the app
account; each change asks for confirmation unless `--force`, and is logged
in the application log with the server user who ran it):

| Command                                       | What it does                                                                                         |
| --------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| `app:create-admin`                            | Make an admin account (the password is prompted, never passed)                                       |
| `app:list-admins`                             | List admins and whether they are active                                                              |
| `app:reset-admin <email> [--send]`            | Print a new password setup link (7 days, single use); `--send` also emails it                        |
| `app:admin-email <current> <new> [--reason=]` | Change an admin's email (lost inbox); audited in `email_audits` as `server`, the old address is told |
| `app:deactivate-admin <email>`                | Stop an admin logging in (signed out too); refused for the last active admin                         |
| `app:reactivate-admin <email>`                | Let a deactivated admin log in again                                                                 |

# HIGANTE

A class scheduling and attendance system for a campus of colleges: sections
and timetables, term registration, present-by-default attendance with
warnings and drops at the handbook's counts, absence excuses decided by each
class's teacher, substitutes covering classes, and a dashboard per role
(admin, registrar, dean, teacher, learner).

There are two kinds of setup, kept apart on purpose:

- **Development or demo**: on your own machine, with sample data and
  development settings. See [Setting up for development](#setting-up-for-development).
- **Real use**: a server with real data, production settings and a real
  mailer. See [Running it for real](#running-it-for-real).

## What it needs

| Component | Version                  | Notes                                                                                                      |
| --------- | ------------------------ | ---------------------------------------------------------------------------------------------------------- |
| PHP       | 8.3 or newer             | Extensions: `pdo_mysql`, `mbstring`, `xml`, `zip`, `gd`, `intl`, `bcmath`; `sockets` for the browser tests |
| Composer  | 2                        |                                                                                                            |
| Node.js   | 22 or newer, with npm    | Builds the frontend                                                                                        |
| MariaDB   | 11.4                     | In Docker, or installed on the machine                                                                     |
| Docker    | Any recent, with Compose | Optional: runs MariaDB, or the whole app                                                                   |
| Chromium  | Installed by Playwright  | Only for `composer test:browser`; `composer setup` downloads it                                            |

Main libraries (installed by Composer and npm, nothing to do by hand):

- Backend: Laravel 13, Laravel Fortify (sign-in, two-factor, passkeys),
  Inertia 3, Wayfinder, PhpSpreadsheet (the learner import sheet)
- Frontend: Vue 3.5, Tailwind CSS 4, reka-ui, Vite+ 0.3 (Vite), TypeScript
- Checks: Pest 5 with its browser plugin (Playwright), Larastan (PHPStan),
  Pint, vue-tsc, Vitest

## Setting up for development

Pick one of two ways:

|          | Everything in Docker                 | PHP and Node on your machine                              |
| -------- | ------------------------------------ | --------------------------------------------------------- |
| Needs    | Docker                               | PHP, Composer, Node, and MariaDB (in Docker or installed) |
| Good for | Trying it out without installing PHP | Day-to-day work: faster, with hot reload                  |

### Everything in Docker

```sh
git clone https://github.com/randAcc07/HIGANTE.git higante
cd higante
docker compose --profile app up
```

The first start installs dependencies, creates `.env` and its key, migrates
and builds the frontend (a few minutes); later starts are quick. Open
<http://localhost:8000>, then in another terminal load the demo campus
(see [Demo data](#demo-data)):

```sh
docker compose exec app composer db:demo
```

Other commands run the same way (`docker compose exec app php artisan …`).
The page shows the built frontend, so after changing Vue files rebuild with
`docker compose exec app npm run build`. This container is for development
only; it is not how the app is served for real.

### PHP and Node on your machine

```sh
git clone https://github.com/randAcc07/HIGANTE.git higante
cd higante
cp .env.example .env
docker compose up -d        # MariaDB in Docker, on port 3307 (or see your system below)
composer setup              # dependencies, key, migrations, Chromium for tests, build
composer db:demo            # the demo campus
composer dev                # server, queue, logs and Vite with hot reload
```

Open <http://localhost:8000>. `docker compose up -d` without the `app`
profile starts only MariaDB. On its first start it creates the database, its
two accounts (see [The database accounts](#the-database-accounts)) and
`higante_test` for the tests.

#### macOS

PHP and Composer from [Laravel Herd](https://herd.laravel.com) or Homebrew
(`brew install php composer`), Node from Homebrew or nodejs.org.

To run MariaDB without Docker, install it with Homebrew. It's keg-only, so
put its client first on the PATH:

```sh
brew install mariadb@11.4
brew services start mariadb@11.4                          # listens on 3306
export PATH="$(brew --prefix mariadb@11.4)/bin:$PATH"
```

Create the database, its accounts and the test database. Homebrew's MariaDB
lets your macOS user in as an administrator, so no password is needed:

```sh
set -a; . ./.env; set +a
DB_TEST_DATABASE=higante_test sh database/provisioning/provision.sh | mariadb
```

Then set `DB_PORT=3306` in `.env` and skip `docker compose up -d`. Use the
`mariadb` command, not `mysql`: another `mysql` on the PATH (MySQL
Workbench's, say) may be a different client. If port 3306 is taken (a MySQL
server, for instance), add `port=3308` under `[mysqld]` in
`$(brew --prefix)/etc/my.cnf`, restart it, and set `DB_PORT=3308`. The
Docker database (3307) and this one can run side by side; `DB_PORT` picks
which one the app uses.

#### Linux

PHP from your distribution (`php8.3-cli` or newer, plus `php-mysql`,
`php-mbstring`, `php-xml`, `php-zip`, `php-gd`, `php-intl`, `php-bcmath`),
Composer, and Node 22+.

For MariaDB without Docker, use MariaDB's own repository
(<https://mariadb.org/download/?t=repo-config>) for 11.4, since distributions
often ship an older one. Its administrator is reached with `sudo mariadb`:

```sh
set -a; . ./.env; set +a
DB_TEST_DATABASE=higante_test sh database/provisioning/provision.sh | sudo mariadb
```

With Everything in Docker, files the container creates in the checkout
(`.env`, logs, `public/build`) belong to root; `sudo chown -R "$USER" .`
fixes that if it gets in the way.

#### Windows

The simplest is Everything in Docker, with Docker Desktop on its WSL 2
backend. Clone the repository inside WSL (e.g. `~/higante`) rather than
under `C:\`, which is much faster. For PHP and Node on the machine, use WSL 2
and follow the Linux notes there. Native Windows (Laravel Herd for PHP,
MariaDB's installer) also works; run the provisioning script from Git Bash
and pipe it into `mariadb -u root -p`.

### Demo data

`composer db:demo` rebuilds the schema and seeds a three-college campus
mid-semester: timetables, registrations, attendance, warnings, excuses,
covers, and the next term in preparation. The logins are printed at the
end; every password is `password`. It refuses to run in production, and
re-running it wipes everything first.

For an empty development setup instead, make the first admin with
`php artisan app:create-admin` and enter the campus in the app.

### Checks

```sh
composer ci:check           # lint, types, unit tests, Pint, colors, PHPStan, Pest
composer test:browser       # pages in a real browser (Playwright Chromium)
```

Both use the `higante_test` database, never your data. `composer setup`
installs Chromium; run `npx playwright install chromium` yourself if you
skipped it.

## Running it for real

Real data never comes from the demo. The short version (the details, and the
reasons, are in [docs/operations.md](docs/operations.md)):

|            | Development or demo                                     | Real use                                                                                                                                                                                      |
| ---------- | ------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `.env`     | `APP_ENV=local`, `APP_DEBUG=true`                       | `APP_ENV=production`, `APP_DEBUG=false`, the site's `APP_URL`, strong database passwords                                                                                                      |
| Data       | `composer db:demo`                                      | Nothing seeded: `php artisan app:create-admin` once, then the admin enters colleges, programs, subjects, rooms, terms and staff; the registrar admits learners (one by one or from the sheet) |
| Schema     | `composer setup` / `db:demo`                            | `php artisan migrate --database=mariadb_migrate --force` on every deploy, never `migrate:fresh` (refused in production)                                                                       |
| Serving    | `composer dev` or the Docker `app` container            | A web server (Nginx or Apache with PHP-FPM) pointed at `public/`, over HTTPS; `npm run build` on deploy                                                                                       |
| Mail       | `MAIL_MAILER=log`: links and notices go to the log      | A real mailer (host and credentials in `.env`); until then, setup links are handed over from the copy-link dialog                                                                             |
| Background | `composer dev` runs a queue worker (polls the database) | No queue worker; a cron entry running `php artisan schedule:run` every minute (daily cleanup only)                                                                                            |
| Admins     | Seeded                                                  | Made and managed on the server with the `app:*` commands                                                                                                                                      |

A real deployment starts the same way as development (provision the database
with `database/provisioning/provision.sh`, then install dependencies and
migrate with the migrate account), but with the production `.env` and none of
the demo steps.

## The database accounts

The app connects with an account that can only read and write data. Schema
changes go through a second account, so migrations always name it:

```sh
php artisan migrate --database=mariadb_migrate
```

Running a migration without the flag fails safely. `provision.sh` makes both
accounts; in Docker it runs when the database volume is first created. If
you change their passwords in `.env` later, run it again (in Docker:
`docker compose exec mariadb sh /docker-entrypoint-initdb.d/initdb.sh`), or
start over with `docker compose down -v`, which deletes the data.

## More

- [docs/feature-checklist.md](docs/feature-checklist.md): what is built, and
  the decisions behind it
- [docs/roles-and-permissions.md](docs/roles-and-permissions.md): who can do
  what
- [docs/operations.md](docs/operations.md): real use in detail, mail, the
  scheduler, and the admin server commands

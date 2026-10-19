# Feature checklist

The build order, one feature per reviewable chunk. Each feature lands as its own
change set: review it, then commit. Roles and their permissions are tracked in
[roles-and-permissions.md](roles-and-permissions.md).

Every feature is done only when:

- `composer ci:check` passes (formatting, lint, TypeScript, Pint, PHPStan, Pest).
- New behaviour has tests, including the permission boundaries (wrong role,
  wrong college).
- Schema changes ran through `php artisan migrate --database=mariadb_migrate`,
  and `db/scheduler_schema.sql` / `.dbml` were regenerated.
- `roles-and-permissions.md` reflects what was built.

## Decisions this plan relies on

| Topic            | Decision                                                                                                                                                                                                        |
| ---------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Names            | Split: last, first, middle (optional), suffix (optional)                                                                                                                                                        |
| Name changes     | Staff (admin, registrar, deans, teachers) can change their own name on the profile page; learners cannot. The admin can also edit staff names, and the registrar learner names                                  |
| Email changes    | Anyone can change their own email; it takes effect only after the new address confirms and the old address approves. The admin can change any user's email directly, for users who lost access to the old inbox |
| First password   | Set-password link by email; the admin or registrar can also copy the link to hand over another way. Mail goes to the log in development                                                                         |
| Deactivation     | `is_active` flag; deactivated users cannot log in; records stay                                                                                                                                                 |
| Timezone         | `Asia/Manila`                                                                                                                                                                                                   |
| Schedule grid    | Mon–Sat, 07:00–20:00, 30-minute steps to start; adjustable stops with zoom later, adapted from LSIMS                                                                                                            |
| Holidays         | Admin enters campus-wide ones; deans enter their own college's                                                                                                                                                  |
| Mark corrections | Teachers correct their own marks; deans can correct marks in their college; every change is audited. No edit time limit for now                                                                                 |
| Teacher view     | Teachers see warning and drop status for learners in their own classes                                                                                                                                          |
| Class period     | One scheduled class on one date counts as one period (Mon and Wed Statics = two a week; a 3-hour lab = one)                                                                                                     |
| Learner import   | Single admission first; CSV import later, once a real file format is known                                                                                                                                      |
| Notifications    | In-app first; email warnings once a mail server exists                                                                                                                                                          |

## 1. Foundation

- [x] Timezone set to `Asia/Manila` (the database session runs at `+08:00` to match)
- [x] Users: split names (`last_name`, `first_name`, `middle_name`, `suffix`) in place of `name`; `is_active`; starter kit pages, factory and `app:create-admin` updated
- [x] Deactivated users are refused at login (password and passkey), and logged out if already signed in
- [x] Eloquent models, enums, relationships and factories for every domain table; factories keep related rows in one college
- [x] Authorization base: `role:` route middleware and role helpers on `UserRole`/`User`. Per-model policies (own college, own classes) arrive with each feature's routes
- [x] Role-aware navigation (one shared menu file per role) and a dashboard showing role and college
- [x] Profile page: staff edit their own name; for learners it is read-only
- [x] Demo data: `composer db:demo` rebuilds the schema (migrate user) and seeds a three-college campus mid-semester (app user): timetables, registrations, attendance, warnings, drop flags, excuses. Logins are printed at the end; password `password`. Extend `database/seeders/Demo/` as features add tables

## 2. Email change with two-step verification

- [x] `email_changes` table (user, new email, expiry, new-confirmed and old-approved times). Instead of LSIMS's stored token hash, the emailed links are signed URLs that expire with the request; deleting the row voids them
- [x] Requesting a change (current password required) emails both addresses; the email changes only after both agree, in either order; links expire after 24 hours. Opening a link only shows a page, so mail scanners cannot answer for anyone; the old address can also cancel ("this wasn't me")
- [x] One pending change per user; a new request replaces the old one. Expired requests are pruned daily (`model:prune`)
- [x] Admin can set any user's email directly (verified, cancels any pending change, reason required), audited in `email_audits` with completed self-service changes; the old address is told. Endpoint only for now: its form comes with the admin user screens in feature 5

## 3. Campus catalog (admin)

- [ ] Colleges and their programs
- [ ] Subjects per college
- [ ] Rooms: building, floor, type, optional capacity, active/inactive

## 4. Terms and calendar

- [ ] Terms (school year, semester, dates) with their attendance policy, defaulting to the handbook (3 lates = 1 absence, warn at 3 and 4, drop at 5)
- [ ] Calendar events: admin for campus-wide, deans for their own college; whether each one suspends classes

## 5. Accounts and admission

- [ ] Admin creates registrar, dean and teacher accounts (deans and teachers get a college)
- [ ] Registrar admits learners: account, student number, program, year level
- [ ] New accounts get a set-password link (emailed, and copyable)
- [ ] Admin and registrar can edit names and deactivate/reactivate within their scope
- [ ] Lists with search and filters (role, college, program, active)

## 6. Dean scheduling

- [ ] Sections for a term (program, year level, name, optional capacity)
- [ ] Subject offerings per section with a default teacher (own college only)
- [ ] Batches per offering
- [ ] Schedules: day, time, room or online, whole offering or one batch, teacher
- [ ] Readable conflict messages for room, teacher and section/batch clashes (the database still rejects room and teacher overlaps)
- [ ] Room capacity warning when known (never blocks)
- [ ] Schedule grid (Vue): Mon–Sat, 07:00–20:00, 30-minute steps; views per section, room and teacher

## 7. Term registration (registrar)

- [ ] Register admitted learners for a term, with year level and irregular flag
- [ ] Auto-sectioning: the emptiest section of the learner's program and year level with room; otherwise unsectioned
- [ ] Regular learners are enrolled in every offering of their section, including offerings the dean adds later
- [ ] Withdrawal keeps the registration and its history

## 8. Dean placement

- [ ] Queue of unsectioned learners; place or move learners between sections (warn past capacity)
- [ ] Enroll irregular learners subject by subject
- [ ] Assign learners to batches
- [ ] Per-learner schedule conflict check when enrolling or batching

## 9. Attendance taking (teacher)

- [ ] Teacher's own schedule and today's classes
- [ ] Class list for a schedule on a date; mark present / late / absent
- [ ] Correct marks; deans can correct marks in their college; audited
- [ ] Marks only on real class days (the schedule's weekday, inside the term, not suspended)

## 10. Absence tracking and drops

- [ ] Expected class periods per enrollment (schedules across the term, minus suspended days)
- [ ] Equivalent absences per enrollment: unexcused absences + floor(lates ÷ lates per absence)
- [ ] Warnings at each warning count, sent once; in-app notifications
- [ ] Flag for dropping at the drop count; recalculate when marks or excuses change (unless the drop was confirmed)
- [ ] Dean confirms or dismisses drops; at-risk list per college
- [ ] Teachers see warning and drop status in their classes

## 11. Excuses

- [ ] Learner submits an excuse: date range, reason, remarks, any number of files
- [ ] Dean reviews; `medical` needs at least one file to be approved
- [ ] Approval excuses the learner's absences in every class in the range (lates stay); revoking restores them
- [ ] Learner sees each excuse's status

## 12. Learner views

- [ ] Own weekly schedule
- [ ] Attendance per class, equivalent absences, warnings and drop status
- [ ] Notifications

## Later

- [ ] Learner CSV import (needs a real file to design against)
- [ ] Copy one subject's batches to the section's other split subjects
- [ ] Schedule grid: adjustable time stops with zoom, adapted from LSIMS (needs LSIMS's frontend code)
- [ ] Email warnings, once a mail server exists
- [ ] Open items from the roles doc: substitutes, guardian excuses, curriculum/prospectus, year-level promotion

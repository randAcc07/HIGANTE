# Feature checklist

The build order, one feature per reviewable chunk. Each feature lands as its own
change set: review it, then commit. Roles and their permissions are tracked in
[roles-and-permissions.md](roles-and-permissions.md).

Every feature is done only when:

- `composer ci:check` passes (formatting, lint, TypeScript, frontend unit
  tests, Pint, PHPStan, Pest).
- `composer test:browser` passes: real pages in Chromium (Pest browser plugin).
  Every new page gets a row in `tests/Browser/SmokeTest.php`; client-side logic
  worth clicking through (ticks, splits, dialogs) gets a flow test there too.
- New behaviour has tests, including the permission boundaries (wrong role,
  wrong college). Pure frontend logic lives in `resources/js/lib/` with a
  `*.test.ts` next to it (`npm test`).
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
| Attendance       | Present unless marked: classes need no "taking"; only late and absent marks are recorded                                                                                                                        |
| Class period     | One scheduled class on one date counts as one period (Mon and Wed Statics = two a week; a 3-hour lab = one)                                                                                                     |
| Year levels      | Go up by one at the 1st semester of a new school year; unchanged for the 2nd. No summer term in practice, but the option stays                                                                                  |
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
- [x] Demo data: `composer db:demo` rebuilds the schema (migrate user) and seeds a three-college campus mid-semester (app user): timetables, registrations, attendance, warnings, drop flags, excuses, last school year's registrations, and an upcoming 2nd semester with sections but nobody registered (for carrying learners over). Logins are printed at the end; password `password`. Extend `database/seeders/Demo/` as features add tables

## 2. Email change with two-step verification

- [x] `email_changes` table (user, new email, expiry, new-confirmed and old-approved times). Instead of LSIMS's stored token hash, the emailed links are signed URLs that expire with the request; deleting the row voids them
- [x] Requesting a change (current password required) emails both addresses; the email changes only after both agree, in either order; links expire after 24 hours. Opening a link only shows a page, so mail scanners cannot answer for anyone; the old address can also cancel ("this wasn't me")
- [x] One pending change per user; a new request replaces the old one. Expired requests are pruned daily (`model:prune`)
- [x] Admin can set any user's email directly (verified, cancels any pending change, reason required), audited in `email_audits` with completed self-service changes; the old address is told. Endpoint only for now: its form comes with the admin user screens in feature 5

## 3. Campus catalog (admin)

- [x] Colleges and their programs (a program's college is fixed once created)
- [x] Subjects per college: codes unique within a college, lecture/lab units to one decimal, college fixed once created; filter by college and search
- [x] Rooms: building, floor, type (lecture, laboratory or venue, e.g. the gym or an AVR), optional warn-only capacity, active/inactive; filter by type, status and search. An optional unique name (labs, the gym) becomes the room's label everywhere (`Room::label`), with the code kept as the short identifier
- [x] Nothing in use is deleted: each delete says what still uses the record (rooms in use are deactivated instead)

## 4. Terms and calendar

- [x] Terms (school year as `2026-2027`, semester, dates) saved with their attendance policy, defaulting to the handbook (3 lates = 1 absence, warn at 3 and 4, drop at 5). Terms never overlap; warnings must come before the drop count; the current term is worked out from today's date
- [x] Term phases: upcoming, in session and ended come from the dates; the admin closes an ended term once its records are final (read-only; can be reopened). Screens that prepare a term open the next upcoming one; screens that view one open the term in session, else the next upcoming, else the latest
- [x] A term with data is never deleted (only empty ones), and its dates can't move away from recorded attendance
- [x] Calendar events: admin for campus-wide, deans for their own college (`CalendarEventPolicy`); whether each one suspends classes. Everyone sees the calendar read-only: campus-wide events plus their own college's (admin and registrar see all)
- [x] Date-only columns serialize as `Y-m-d`, so Manila's UTC+8 can't move a date to the day before

## 5. Accounts and admission

- [x] Admin creates registrar, dean and teacher accounts (deans and teachers get a college; teachers an employee number and duty status). Role and college are fixed once made. Admin accounts are peers, so no admin controls another: they are made and kept on the server (`php artisan app:create-admin`)
- [x] Registrar admits learners: account, student number, program, year level, status; the learner's college follows the program. A program shift waits until the learner's current and coming terms are over
- [x] New accounts get a set-password link: emailed, and shown once to whoever made the account to copy. Signed, 7 days, works once (voided when the password changes); doubles as an admin-assisted password reset. Accounts made by staff start with a verified email
- [x] Admin edits registrar, dean and teacher names and teacher details, overrides the email of anyone but an admin (feature 2's form), and deactivates/reactivates those staff. Registrar edits learner records and names, and deactivates/reactivates learners. Someone who forgot their password and lost their inbox gets the email overridden, then a new setup link handed over
- [x] Lists with search and filters: accounts (role, college, active), learners (college, program, year level, status)

## 6. Dean scheduling

- [x] Sections for a term (program, year level, name, optional capacity); term, program and year level fixed once made. Opens on the next upcoming term
- [x] Subject offerings per section with a default teacher (own college only, or TBA); changing the teacher can move the subject's classes too (substitutes), checked against the new teacher's week
- [x] Batches per offering (named, e.g. 1-AA / 1-AB)
- [x] Schedules: day, time, room or online, whole offering or one batch, teacher
- [x] Readable conflict messages for room, teacher and section/batch clashes, all listed at once (the database still rejects room and teacher overlaps). Batch classes with different names may run together, even of different subjects (2-AA's Physics lab while 2-AB has Chemistry); the same batch name is the same learners and clashes
- [x] Room capacity and teacher-on-leave warnings when saving (never block)
- [x] Schedule grid (Vue): Mon–Sat, 07:00–20:00, classes in 30-minute steps; zoom 50% / 100% / 200% / 600% via − / +, Ctrl+scroll or pinch (adapted from LSIMS); lines and labels stay at 30 minutes (hourly at 50%), matching the 30-minute scheduling step. Classes are scheduled on the Timetables page, by view type (section, optionally as one batch sees it; room; teacher) then target: click a slot to add (from a room or teacher, the dialog also asks for the section), a class to edit. Section pages keep subjects and batches. Other colleges' bookings of a room show only as booked
- [x] A closed term's sections, offerings and schedules are read-only; a class with attendance keeps its day and time and can't be removed
- [x] Nothing with learners is deleted: sections with registrations, subjects with recorded attendance (feature 7 relaxed this from any enrollment), batches with classes or learners

## 7. Term registration (registrar)

Learners re-enroll at the registrar's office once per school year; the
registrar's approval in the system is the enrollment. There is no learner-side
confirmation (it would duplicate the office visit) and no enrollment window.

- [x] 1st semester: the registrar works from a list of last year's active learners, year level already raised by one (at most 6), nobody ticked; ticks and approves them (singly or the ticked ones on a page) as they complete enrollment. Learners not on the list (returnees, learners admitted without registering) are under "others", at their record's year level
- [x] 2nd semester: the same list, pre-ticked with everyone registered in the 1st semester of the same school year (withdrawn learners unticked), same year level and irregular flag; the registrar unticks those who left
- [x] New learners (freshmen, transferees) are admitted and registered directly (the admit form's "Register for" term)
- [x] The registrar can override the proposed year level (returnees, irregulars) and mark a registration irregular (no automatic enrollment in the section's subjects). Made regular later, the learner gets the section's subjects they lack; made irregular, they keep their subjects for the dean
- [x] Approval registers the learner and auto-sections them: the emptiest section of their program and year level with room (ties to the first by name); otherwise unsectioned for the dean. A 2nd semester keeps the block: the section named like the learner's 1st-semester one, even past capacity (warned), else the emptiest. The learner record's year level follows their latest registration
- [x] Regular learners are enrolled in every offering of their section, including offerings the dean adds later (batches are left for the dean). An offering can be removed while none of its enrollments has attendance; its enrollments go with it
- [x] Withdrawal keeps the registration and its history and drops every class the learner still has (confirmed by the registrar). A registration made by mistake can be undone (removed with its enrollments) until attendance is recorded
- [x] Registration changes only while the term is upcoming or in session; an ended or closed term's registrations are read-only
- [ ] Later, if enrollment moves online: learner-side confirmation as a status that feeds the same list

## 8. Dean placement

- [x] Queue of unsectioned learners (the dean's Learners page, per term, filtered to "No section"); place or move learners between sections of their program and year level (warn past capacity). Moving a regular learner swaps the old section's subjects for the new one's (subjects with attendance stay as dropped, confirmed by the dean); an irregular learner keeps their subjects
- [x] Enroll learners subject by subject from any section of the college this term, optionally in a batch; removing a subject deletes it, or marks it dropped once attendance is recorded; a dropped subject can be taken again (same enrollment, history kept). Adding or removing a subject for a regular learner makes them irregular
- [x] Assign learners to batches: the section page's roster (everyone taking its subjects, including irregulars from other sections) with one batch per split subject and "set all" by batch name; "Split automatically" places everyone without a batch by last name, in blocks, toward an even split within each batch's lab seats (those with a batch stay, and get the same batch in subjects they lack one in); tick learners to put them in a batch in every split subject or one. Nothing is saved until "Save batches", which saves the whole roster; one subject's batch can also be changed from the learner's page
- [x] Per-learner schedule conflict check when enrolling or batching (`LearnerConflicts`): refused with every clash listed, e.g. 2-AA of one subject and 2-AB of another at the same time. Saving a class warns (never blocks) when a learner in it has another class then
- [x] The learner's page shows their week (whole-class meetings plus their batches'); placement is the learner's own dean's, while they are registered and the term isn't closed

## 9. Attendance taking (teacher)

- [x] Teacher's own schedule and today's classes ("My classes": any day's classes with how many were late or absent, plus the week)
- [x] Class list for a schedule on a date; mark present / late / absent. Everyone counts as present unless marked otherwise: there is no "taking" step, only late and absent marks are recorded (a mark put back to present keeps its record for the history). A class that didn't happen counts as all present unless someone marks it — accepted, to spare everyone the upkeep. A learner is listed from the day they enrolled in the subject until it was dropped; a batch's class lists only that batch
- [x] Correct marks; deans can correct marks in their college (the dean's Attendance page: a day's classes by section or teacher, opening the same class list); every new or changed mark gets an audit row
- [x] Marks only on real class days: the schedule's weekday, inside the term, not suspended (campus-wide or the college's own), and not in the future; any earlier class day can still be taken or corrected (no time limit)
- [x] A closed term's attendance is read-only; an excused absence (approved excuse) stays locked

## 10. Absence tracking and drops

- [x] Expected class periods per enrollment: held so far and in the whole term, from the day they enrolled to the day it was dropped, minus suspended meetings (shown on the at-risk list)
- [x] Partial-day suspensions: a suspension may carry an optional from and/or until time (blank = whole day). It catches the classes that start inside the window ("from 1:00 PM" leaves a lab that began at noon held). Class lists, meeting dates and period counts all follow it
- [x] Suspension memos: a calendar event can record its memorandum number and attach the memo (PDF, 10 MB); the calendar links it for everyone who can see the event
- [x] Marks on a day that was suspended afterwards (e.g. a typhoon entered the next day) stay stored but don't count; the class list shows them read-only with a note. Lifting the suspension counts them again (adding, changing or deleting a suspension recounts everyone marked in its days)
- [x] Equivalent absences per enrollment: unexcused absences + floor(lates ÷ lates per absence), under the term's policy (the handbook's when a term has none)
- [x] Warnings at each warning count, in-app: each newly reached count is notified once; a correction lowers the level quietly, and reaching it again warns again
- [x] Flag for dropping at the drop count (learner and the college's deans are told); recalculated whenever marks or suspensions change, so corrections lift the flag; a confirmed drop stays until reinstated
- [x] Dean confirms or dismisses drops on the at-risk list (for dropping / warned / dropped, per section): a dismissal holds until the next equivalent absence; an absence drop can be reinstated (recorded, and held like a dismissal). Drops now record why (absences, withdrawn, removed); only absence drops can be reinstated
- [x] Teachers see warning and drop status on their class lists
- [x] A notification bell in the header for every role: unread count, latest notices, mark one or all read

## 11. Excuses

Excuses are decided class by class (reworked after the first build, which had the dean decide whole excuses): the college dean oversees schedules and drops, and the teachers who see the learner in class judge the excuses.

- [x] Learner submits an excuse: date range (one day, or up to 31 days of a term they're registered for; not a closed term), reason, remarks, any number of files (up to 10 PDFs or photos of 10 MB each). Each class with a meeting in the range (the learner's batch, not suspended, while enrolled) gets its own decision, and its teachers are notified. Once submitted, an excuse is never deleted. Excuses are due within 7 days after their last day (`AbsenceExcuse::DUE_WITHIN_DAYS`): later ones are still accepted but flagged late, and the learner is warned before and after submitting
- [x] A teacher of the class decides it (whoever teaches the learner's meetings of it now: lecture or their batch's lab, so a substitute takes over pending ones; decisions already made keep who made them) from their Excuses queue and the class's decision page (files, the absences in their class); `medical` needs at least one file to be approved, `illness` is the teacher's discretion. Not approving or revoking needs remarks for the learner
- [x] A teacher can file an excuse for a learner in one of their classes (e.g. a paper certificate handed to them): approved in their own classes, the learner's other teachers decide theirs
- [x] The dean oversees every excuse of the college and how each class decided (read-only), and decides only for a class without an active teacher
- [x] Approval excuses the learner's absences in that class on the excuse's days (lates stay), including absences marked later; revoking it (per class) restores them, or moves them to another excuse approved for the class covering the day. Each link and unlink is audited and the standing recounted. The excuse's status summarizes its classes: pending, approved, partly approved, not approved, revoked
- [x] Learner sees each excuse's classes and how each decided, with the teacher's remarks, and is notified of every decision
- [x] Excuses covering a closed term's days can't be decided or revoked
- [x] Drop the old excuse-level review columns (`reviewed_by`, `reviewed_at`, `review_remarks`, `revoked_at`, `revoked_by`, `revoke_remarks` on `absence_excuses`) once the demo seeder writes decisions

## 12. Learner views

- [x] Own weekly schedule ("My classes", per registered term, past terms read-only), with their batches' labs
- [x] Attendance per class, equivalent absences, warnings and drop status: per class the equivalent count of the drop count, absent / late / excused, classes held of the term's total and a standing badge; each class lists the learner's late and absent days and whether each counts (excused, or a day suspended afterwards). Only the mark is shown, not who recorded it. The dashboard lists classes that need attention and excuses awaiting the dean
- [x] Notifications: a full page for every role (all or unread only, mark read), linked from the bell's "See all"

## Later

- [ ] Learner CSV import (needs a real file to design against)
- [ ] Server commands for admin accounts, which no admin can change from the app: e.g. `app:reset-admin <email>` printing a new setup link, plus changing an admin's email and deactivating/reactivating an admin
- [ ] Copy one subject's batches to the section's other split subjects
- [ ] Suspensions for only some programs or year levels of a college (e.g. 4th years for a job fair), if such memos turn out to be common
- [ ] Email warnings, once a mail server exists (AttendanceNotice is database-only for now)
- [ ] Open items from the roles doc: substitutes, guardian excuses, curriculum/prospectus

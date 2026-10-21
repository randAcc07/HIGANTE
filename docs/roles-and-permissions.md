# Roles and permissions

What each role can do, and how far along each piece is. Update this file in
the same commit as the feature that changes it.

**Status:** ✅ built · ⏳ pending (agreed, not built) · ❓ open (not decided) · 🚫 removed

Roles are exclusive: a user has exactly one. The admin and the registrar are
campus-wide; everyone else belongs to one college.

Structure: a **college** has **programs** (what the school calls courses, e.g.
BS Civil Engineering) and **subjects** (e.g. CE201 Statics). Subjects belong to
the college and can be offered to any section of its programs.

## Admin (campus-wide)

| Responsibility                                                                                                                                                                                                        | Status |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| Create the first admin account (`php artisan app:create-admin`)                                                                                                                                                       | ✅     |
| Manage colleges and their programs                                                                                                                                                                                    | ✅     |
| Manage rooms (shared campus-wide; any dean can book any room; optional capacity, warn-only)                                                                                                                           | ✅     |
| Manage terms and each term's attendance policy (lates per absence, warning counts, drop count, optional teacher edit limit)                                                                                           | ✅     |
| Manage subjects (each owned by one college)                                                                                                                                                                           | ✅     |
| Create registrar, dean and teacher accounts                                                                                                                                                                           | ✅     |
| Admin accounts: made and kept on the server (`app:create-admin`, `app:reset-admin`, `app:admin-email`, `app:deactivate-admin` / `app:reactivate-admin`, see operations.md); no admin controls another admin's account | ✅     |
| Deactivate and reactivate registrar, dean and teacher accounts                                                                                                                                                        | ✅     |
| Edit registrar, dean and teacher names and teacher details; change anyone's email but an admin's directly (for lost inboxes)                                                                                          | ✅     |
| Campus-wide holidays and class suspensions                                                                                                                                                                            | ✅     |

## Registrar (campus-wide)

| Responsibility                                                                                                                                                                         | Status |
| -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| Admit learners: create the account and student record, set program and year level                                                                                                      | ✅     |
| Admit learners in bulk from the xlsx template: checked whole first, then imported, optionally registered for a term                                                                    | ✅     |
| Edit learners' names                                                                                                                                                                   | ✅     |
| Deactivate and reactivate learner accounts; send new setup links                                                                                                                       | ✅     |
| Register learners for a term from last year's list (1st semester: tick as each one enrolls; 2nd semester: carried over, untick who left), which places them in a section automatically | ✅     |
| Mark a registration as irregular (no automatic enrollment in the section's subjects)                                                                                                   | ✅     |
| Register freshmen and transferees as they are admitted; withdraw a learner (drops their classes, keeps the record); undo a mistaken registration until attendance is recorded          | ✅     |

## Dean (own college only; does not teach)

| Responsibility                                                                                                                  | Status |
| ------------------------------------------------------------------------------------------------------------------------------- | ------ |
| Create sections for a term (optional capacity) before registration opens                                                        | ✅     |
| Copy an earlier term's sections (one year up for a new school year), keeping blocks together through registration               | ✅     |
| Create subject offerings for sections; split an offering into uneven batches                                                    | ✅     |
| Create schedules: day, time, any campus room, onsite or online, whole offering or one batch                                     | ✅     |
| Assign teachers from their own college only                                                                                     | ✅     |
| Place learners auto-sectioning could not fit; move learners between sections                                                    | ✅     |
| Copy one subject's batches to the section's other split subjects                                                                | ✅     |
| Keep each program's curriculum (subjects by year level and semester); new sections get their subjects from it                   | ✅     |
| Enroll irregular learners subject by subject                                                                                    | ✅     |
| Assign learners to batches (section roster, or per subject on the learner's page), checked against each learner's week          | ✅     |
| Oversee their college's excuses and each class's decision; decide only for a class without an active teacher                    | ✅     |
| Confirm or dismiss drops flagged at the policy's drop count                                                                     | ✅     |
| Reinstate a learner dropped for absences                                                                                        | ✅     |
| Partial-day suspensions, suspensions for some programs / year levels, and suspension memos (number + PDF) for their own college | ✅     |
| See an at-risk learners list                                                                                                    | ✅     |
| College-only holidays and suspensions for their own college                                                                     | ✅     |
| Correct a teacher's attendance marks                                                                                            | ✅     |
| Reassign a subject's classes to another teacher for good                                                                        | ✅     |
| Arrange cover for a teacher away: a substitute per class for a range of dates, never double-booked                              | ✅     |

## Teacher (own college only)

| Responsibility                                                                                                                       | Status |
| ------------------------------------------------------------------------------------------------------------------------------------ | ------ |
| See own schedule and class lists                                                                                                     | ✅     |
| Mark present / late / absent per scheduled class, by their own judgment                                                              | ✅     |
| Correct own marks (every change is audited)                                                                                          | ✅     |
| Mark the classes they cover as a substitute, on those days only (excuses stay with the class's teacher)                              | ✅     |
| Decide excuses for their own classes (approve / not approve / revoke; `medical` needs a file); file one for a learner in their class | ✅     |
| An excused absence stays locked on the class list (the excuse decides it)                                                            | ✅     |
| See learners' warning and drop status in their classes                                                                               | ✅     |
| Change marks up to the term's limit after a class, if the admin sets one (none by default); the dean corrects after that             | ✅     |

## Learner

| Responsibility                                                                                | Status |
| --------------------------------------------------------------------------------------------- | ------ |
| See own schedule                                                                              | ✅     |
| See attendance per class and equivalent absences                                              | ✅     |
| Get warnings at the policy's warning counts; get notified when flagged or dropped             | ✅     |
| Submit excuses with any number of files and track their status                                | ✅     |
| Guardian accounts for excuses (a parent's letter is attached to the learner's excuse instead) | 🚫     |

## All users

| Responsibility                                                                                                                            | Status |
| ----------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| Log in, reset password, two-factor login, passkeys                                                                                        | ✅     |
| See the calendar: campus-wide events plus their own college's (admin and registrar see all)                                               | ✅     |
| Change own name (staff only; learners' names are changed by the registrar)                                                                | ✅     |
| Change own email, confirmed by both the new and the old address                                                                           | ✅     |
| Change own password                                                                                                                       | ✅     |
| Forgot the password and lost the inbox: staff override the email and hand over a new setup link (registrar for learners, admin for staff) | ✅     |
| Public sign-up                                                                                                                            | 🚫     |
| Delete own account                                                                                                                        | 🚫     |

## System

| Responsibility                                                                                                                                 | Status |
| ---------------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| Reject double-booked rooms and teachers, plus the other database rules                                                                         | ✅     |
| Place newly registered learners in the emptiest section of their program and year level that has room; leave them for the dean if all are full | ✅     |
| Enroll section members in every offering of their section, including offerings added later                                                     | ✅     |
| Count expected class days per offering, skipping holidays and suspensions                                                                      | ✅     |
| Calculate equivalent absences; send each warning once                                                                                          | ✅     |
| Flag for dropping at the drop count; lower or clear warnings and flags when marks are corrected (unless the dean already confirmed the drop)   | ✅     |
| Apply an approved excuse to every class in its date range                                                                                      | ✅     |
| Catch room, teacher and section/batch schedule conflicts (per-learner conflicts come with enrollment)                                          | ✅     |
| Refuse enrolling or batching a learner into two classes at once; warn when a class is scheduled over a learner's other class                   | ✅     |

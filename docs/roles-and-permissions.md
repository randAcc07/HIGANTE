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

| Responsibility                                                                                                                                                                 | Status                   |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------ |
| Create the first admin account (`php artisan app:create-admin`)                                                                                                                | ✅                       |
| Manage colleges and their programs                                                                                                                                             | ⏳                       |
| Manage rooms (shared campus-wide; any dean can book any room; optional capacity, warn-only)                                                                                    | ⏳                       |
| Manage terms and each term's attendance policy (lates per absence, warning counts, drop count)                                                                                 | ⏳                       |
| Manage subjects (each owned by one college)                                                                                                                                    | ⏳                       |
| Create registrar, dean and teacher accounts                                                                                                                                    | ⏳                       |
| Deactivate accounts (replaces self-deletion). Login already refuses inactive accounts; the admin screen is pending                                                             | ⏳                       |
| Edit staff names; change any user's email directly (for lost inboxes). The email override works (with a reason, audited); its form and name editing come with the user screens | ⏳                       |
| Campus-wide holidays and class suspensions                                                                                                                                     | ❓ admin or someone else |

## Registrar (campus-wide)

| Responsibility                                                                       | Status |
| ------------------------------------------------------------------------------------ | ------ |
| Admit learners: create the account and student record, set program and year level    | ⏳     |
| Edit learners' names                                                                 | ⏳     |
| Register learners for a term, which places them in a section automatically           | ⏳     |
| Mark a registration as irregular (no automatic enrollment in the section's subjects) | ⏳     |

## Dean (own college only; does not teach)

| Responsibility                                                                              | Status |
| ------------------------------------------------------------------------------------------- | ------ |
| Create sections for a term (optional capacity) before registration opens                    | ⏳     |
| Create subject offerings for sections; split an offering into uneven batches                | ⏳     |
| Create schedules: day, time, any campus room, onsite or online, whole offering or one batch | ⏳     |
| Assign teachers from their own college only                                                 | ⏳     |
| Place learners auto-sectioning could not fit; move learners between sections                | ⏳     |
| Copy one subject's batches to the section's other split subjects                            | ⏳     |
| Enroll irregular learners subject by subject                                                | ⏳     |
| Approve or reject excuses for their college's learners (`medical` needs at least one file)  | ⏳     |
| Confirm or dismiss drops flagged at the policy's drop count                                 | ⏳     |
| See an at-risk learners list                                                                | ⏳     |
| College-only holidays and suspensions (schema supports it)                                  | ❓     |
| Correct a teacher's attendance marks                                                        | ❓     |
| Reassign schedules to a substitute when a teacher is on leave                               | ❓     |

## Teacher (own college only)

| Responsibility                                                          | Status |
| ----------------------------------------------------------------------- | ------ |
| See own schedule and class lists                                        | ⏳     |
| Mark present / late / absent per scheduled class, by their own judgment | ⏳     |
| Correct own marks (every change is audited)                             | ⏳     |
| Cannot undo an excuse the dean approved (enforced by the database)      | ⏳     |
| See learners' warning and drop status in their classes                  | ❓     |
| Time limit on editing marks after a class                               | ❓     |

## Learner

| Responsibility                                                                    | Status |
| --------------------------------------------------------------------------------- | ------ |
| See own schedule                                                                  | ⏳     |
| See attendance per class and equivalent absences                                  | ⏳     |
| Get warnings at the policy's warning counts; get notified when flagged or dropped | ⏳     |
| Submit excuses with any number of files and track their status                    | ⏳     |
| Whether a guardian can submit excuses too                                         | ❓     |

## All users

| Responsibility                                                             | Status |
| -------------------------------------------------------------------------- | ------ |
| Log in, reset password, two-factor login, passkeys                         | ✅     |
| Change own name (staff only; learners' names are changed by the registrar) | ✅     |
| Change own email, confirmed by both the new and the old address            | ✅     |
| Change own password                                                        | ✅     |
| Public sign-up                                                             | 🚫     |
| Delete own account                                                         | 🚫     |

## System

| Responsibility                                                                                                                                 | Status |
| ---------------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| Reject double-booked rooms and teachers, plus the other database rules                                                                         | ✅     |
| Place newly registered learners in the emptiest section of their program and year level that has room; leave them for the dean if all are full | ⏳     |
| Enroll section members in every offering of their section, including offerings added later                                                     | ⏳     |
| Count expected class days per offering, skipping holidays and suspensions                                                                      | ⏳     |
| Calculate equivalent absences; send each warning once                                                                                          | ⏳     |
| Flag for dropping at the drop count; lower or clear warnings and flags when marks are corrected (unless the dean already confirmed the drop)   | ⏳     |
| Apply an approved excuse to every class in its date range                                                                                      | ⏳     |
| Catch section, batch and per-learner schedule conflicts                                                                                        | ⏳     |

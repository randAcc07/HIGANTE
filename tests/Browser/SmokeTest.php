<?php

use App\Models\Enrollment;
use Tests\Browser\Campus;

/*
 * Every page each role can open renders in a real browser. A Vue page that
 * throws while setting up renders blank, and the plugin's own error check
 * misses that (the app boots in a promise, so it is an unhandled rejection),
 * so each page must also show a text of its own: never one the sidebar or
 * breadcrumbs carry too.
 *
 * One page per test: within a test, the plugin keeps showing the first page.
 */

dataset('pages', [
    // Admin
    'admin dashboard' => ['admin', fn (Campus $c) => path('dashboard'), 'Admin · Campus-wide'],
    'admin calendar' => ['admin', fn (Campus $c) => path('calendar.index'), '{term}'],
    'admin accounts' => ['admin', fn (Campus $c) => path('admin.accounts.index'), 'Registrar, dean and teacher accounts are made here'],
    'admin colleges' => ['admin', fn (Campus $c) => path('admin.colleges.index'), 'College of Science'],
    'admin college' => ['admin', fn (Campus $c) => path('admin.colleges.show', $c->college), 'BS Computer Science'],
    'admin subjects' => ['admin', fn (Campus $c) => path('admin.subjects.index'), 'Introduction to Computing'],
    'admin rooms' => ['admin', fn (Campus $c) => path('admin.rooms.index'), 'LAB-1'],
    'admin terms' => ['admin', fn (Campus $c) => path('admin.terms.index'), 'Semesters and the attendance policy'],
    'profile' => ['admin', fn (Campus $c) => path('profile.edit'), 'Email address'],
    'security (behind password confirmation)' => ['admin', fn (Campus $c) => path('password.confirm'), 'Confirm your password'],
    'appearance' => ['admin', fn (Campus $c) => path('appearance.edit'), 'Appearance'],

    // Registrar
    'registrar learners' => ['registrar', fn (Campus $c) => path('registrar.learners.index'), 'Admission and student records'],
    'registrar list' => ['registrar', fn (Campus $c) => path('registrar.registrations.index', ['term' => $c->term->id]), 'Nobody left to register here.'],
    'registrar others' => ['registrar', fn (Campus $c) => path('registrar.registrations.index', ['term' => $c->term->id, 'source' => 'others']), 'Active learners not on the list'],
    'registrar registered' => ['registrar', fn (Campus $c) => path('registrar.registrations.index', ['term' => $c->term->id, 'view' => 'registered']), 'Waiting for the dean'],

    // Dean
    'dean sections' => ['dean', fn (Campus $c) => path('dean.sections.index', ['term' => $c->term->id]), '1-A'],
    'dean section' => ['dean', fn (Campus $c) => path('dean.sections.show', $c->section), 'Learners and batches'],
    'dean timetable by section' => ['dean', fn (Campus $c) => path('dean.timetables.index', ['term' => $c->term->id, 'view' => 'section', 'target' => $c->section->id]), 'CS 101'],
    'dean timetable by room' => ['dean', fn (Campus $c) => path('dean.timetables.index', ['term' => $c->term->id, 'view' => 'room', 'target' => $c->lab_room->id]), 'CS 102'],
    'dean timetable by teacher' => ['dean', fn (Campus $c) => path('dean.timetables.index', ['term' => $c->term->id, 'view' => 'teacher', 'target' => $c->teacher->id]), 'CS 101'],
    'dean learners' => ['dean', fn (Campus $c) => path('dean.learners.index', ['term' => $c->term->id]), 'Abad'],
    'dean learner' => ['dean', fn (Campus $c) => path('dean.learners.show', $c->registrations[0]), 'Whole-class meetings plus'],
    'dean unsectioned learner' => ['dean', fn (Campus $c) => path('dean.learners.show', $c->registrations[3]), 'No subjects yet.'],
    'dean calendar' => ['dean', fn (Campus $c) => path('calendar.index'), '{term}'],

    'dean attendance' => ['dean', fn (Campus $c) => path('dean.attendance.index', ['term' => $c->term->id, 'date' => $c->lastLecture()]), 'open a class to correct its marks'],
    'dean at risk' => ['dean', fn (Campus $c) => path('dean.standing.index', ['term' => $c->term->id]), 'Nobody here: no warnings or drops match.'],
    'dean excuses' => ['dean', fn (Campus $c) => path('dean.excuses.index'), 'No pending excuses.'],
    'dean class list' => ['dean', fn (Campus $c) => path('attendance.show', ['schedule' => $c->lectureClass, 'date' => $c->lastLecture()]), 'Everyone counts as present unless marked otherwise.'],

    // Teacher and learner
    'teacher classes' => ['teacher', fn (Campus $c) => path('teacher.classes.index', ['date' => $c->lastLecture()]), 'Open a class to take or correct its attendance'],
    'teacher class list' => ['teacher', fn (Campus $c) => path('attendance.show', ['schedule' => $c->lectureClass, 'date' => $c->lastLecture()]), 'Everyone counts as present unless marked otherwise.'],
    'teacher excuses' => ['teacher', fn (Campus $c) => path('teacher.excuses.index'), 'Nothing to decide right now.'],
    'teacher dashboard' => ['teacher', fn (Campus $c) => path('dashboard'), 'Welcome,'],
    'teacher calendar' => ['teacher', fn (Campus $c) => path('calendar.index'), '{term}'],
    'learner dashboard' => ['learner', fn (Campus $c) => path('dashboard'), 'Welcome,'],
    'learner calendar' => ['learner', fn (Campus $c) => path('calendar.index'), '{term}'],
    'learner classes' => ['learner', fn (Campus $c) => path('learner.classes.index'), 'Excused absences don\'t count.'],
    'learner class' => ['learner', fn (Campus $c) => path('learner.classes.show', Enrollment::where('learner_id', $c->registrations[0]->learner_id)->where('subject_offering_id', $c->lecture->id)->sole()), 'present at every class so far'],
    'learner dashboard attention' => ['learner', fn (Campus $c) => path('dashboard'), 'Needs your attention'],
    'dean notifications' => ['dean', fn (Campus $c) => path('notifications.index'), 'No notifications yet.'],
    'learner excuses' => ['learner', fn (Campus $c) => path('learner.excuses.index'), 'No excuses yet.'],
    'learner profile' => ['learner', fn (Campus $c) => path('profile.edit'), 'Email address'],
]);

// "{term}" in a text stands for the campus term's label.
it('renders', function (string $role, Closure $path, string $text) {
    $campus = Campus::build();
    $this->actingAs($campus->users[$role]);
    $url = $path($campus);

    visit($url)
        ->assertPathIs((string) parse_url($url, PHP_URL_PATH))
        ->assertSee(str_replace('{term}', $campus->term->label, $text))
        ->assertNoSmoke();
})->with('pages');

<?php

namespace Database\Seeders\Demo;

use App\Enums\RoomType;
use App\Models\Term;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The fixed shape of the demo campus: colleges, programs, first-semester
 * curricula, rooms and the current term's calendar. Everything random
 * (people, sections' sizes, timetables, attendance) is built from this.
 *
 * Subject units decide how a subject is timetabled (see SectionSeeder):
 * 3 lecture units meet twice a week for 1.5 hours, 2 lecture units once for
 * 2 hours, and each laboratory unit is one 3-hour lab per batch.
 *
 * Dates here are written for the week of ANCHOR. Seeded in another week,
 * every fixed date (terms, calendar events, registration and withdrawal)
 * moves by the same whole number of weeks, so weekdays and the gaps between
 * things stay as written and the demo always sits at the same point of its
 * term relative to today. Read them through date() and term(), and find the
 * seeded terms with currentTerm() / nextTerm().
 */
final class CampusData
{
    /** The Monday of the week the dates below were written for. */
    public const ANCHOR = '2026-10-19';

    /** The current term, as written (see term()). Attendance runs from its start to yesterday. */
    public const TERM = ['school_year' => '2026-2027', 'semester' => 'first', 'starts_on' => '2026-08-10', 'ends_on' => '2026-12-18'];

    /**
     * Last school year's terms, kept as history: sections and registrations
     * (one year level lower) for today's 2nd to 4th years, no classes.
     */
    public const PAST_TERMS = [
        ['school_year' => '2025-2026', 'semester' => 'first', 'starts_on' => '2025-08-11', 'ends_on' => '2025-12-19'],
        ['school_year' => '2025-2026', 'semester' => 'second', 'starts_on' => '2026-01-12', 'ends_on' => '2026-05-22'],
    ];

    /**
     * The upcoming term: the current term's sections with a few subjects
     * each, nobody registered yet, so the registrar can carry the current
     * term's learners over.
     */
    public const NEXT_TERM = ['school_year' => '2026-2027', 'semester' => 'second', 'starts_on' => '2027-01-11', 'ends_on' => '2027-05-21'];

    public const EMAIL_DOMAIN = 'higante.test';

    /**
     * Colleges keyed by code. `room_prefix` names the college building's
     * lecture rooms; `labs` maps a lab group to its rooms (code => capacity).
     *
     * @var array<string, array{name: string, building: string, room_prefix: string, lecture_rooms: list<int>, labs: array<string, array<string, int>>}>
     */
    public const COLLEGES = [
        'COS' => [
            'name' => 'College of Science',
            'building' => 'Science Building',
            'room_prefix' => 'COS',
            'lecture_rooms' => [101, 102, 201, 202, 301],
            'labs' => [
                'computer' => ['CL-1' => 25, 'CL-2' => 20],
                'science' => ['SL-1' => 24, 'SL-2' => 24],
            ],
        ],
        'COE' => [
            'name' => 'College of Education',
            'building' => 'Education Building',
            'room_prefix' => 'COE',
            'lecture_rooms' => [101, 102, 103, 201, 202, 203],
            'labs' => [
                'multimedia' => ['ML-1' => 30],
            ],
        ],
        'COEng' => [
            'name' => 'College of Engineering',
            'building' => 'Engineering Building',
            'room_prefix' => 'COEng',
            'lecture_rooms' => [101, 102, 201, 202, 301],
            'labs' => [
                'engineering' => ['EL-1' => 20, 'EL-2' => 20],
                'drafting' => ['DL-1' => 30],
                'chemistry' => ['CHL-1' => 25],
            ],
        ],
    ];

    /**
     * Shared rooms. General education classes use the main building; PE uses
     * the gym and the covered court (venues). MB-304 is inactive (under
     * renovation). Rooms without a `room_type` are lecture rooms.
     *
     * @var list<array{code: string, building: string, floor: int|null, capacity: int|null, is_active: bool, room_type?: RoomType}>
     */
    public const SHARED_ROOMS = [
        ['code' => 'MB-101', 'building' => 'Main Building', 'floor' => 1, 'capacity' => 45, 'is_active' => true],
        ['code' => 'MB-102', 'building' => 'Main Building', 'floor' => 1, 'capacity' => 45, 'is_active' => true],
        ['code' => 'MB-103', 'building' => 'Main Building', 'floor' => 1, 'capacity' => 40, 'is_active' => true],
        ['code' => 'MB-104', 'building' => 'Main Building', 'floor' => 1, 'capacity' => null, 'is_active' => true],
        ['code' => 'MB-201', 'building' => 'Main Building', 'floor' => 2, 'capacity' => 45, 'is_active' => true],
        ['code' => 'MB-202', 'building' => 'Main Building', 'floor' => 2, 'capacity' => 45, 'is_active' => true],
        ['code' => 'MB-203', 'building' => 'Main Building', 'floor' => 2, 'capacity' => 40, 'is_active' => true],
        ['code' => 'MB-204', 'building' => 'Main Building', 'floor' => 2, 'capacity' => null, 'is_active' => true],
        ['code' => 'MB-301', 'building' => 'Main Building', 'floor' => 3, 'capacity' => 50, 'is_active' => true],
        ['code' => 'MB-302', 'building' => 'Main Building', 'floor' => 3, 'capacity' => 50, 'is_active' => true],
        ['code' => 'MB-303', 'building' => 'Main Building', 'floor' => 3, 'capacity' => 40, 'is_active' => true],
        ['code' => 'MB-304', 'building' => 'Main Building', 'floor' => 3, 'capacity' => 40, 'is_active' => false],
        ['code' => 'GYM', 'building' => 'Gymnasium', 'floor' => null, 'capacity' => null, 'is_active' => true, 'room_type' => RoomType::Venue],
        ['code' => 'COURT', 'building' => 'Covered Court', 'floor' => null, 'capacity' => null, 'is_active' => true, 'room_type' => RoomType::Venue],
    ];

    /**
     * Rooms known by name rather than code: the labs and the PE venues.
     *
     * @var array<string, string>
     */
    public const ROOM_NAMES = [
        'CL-1' => 'Computer Laboratory 1',
        'CL-2' => 'Computer Laboratory 2',
        'SL-1' => 'Science Laboratory 1',
        'SL-2' => 'Science Laboratory 2',
        'ML-1' => 'Multimedia Laboratory',
        'EL-1' => 'Engineering Laboratory 1',
        'EL-2' => 'Engineering Laboratory 2',
        'DL-1' => 'Drafting Laboratory',
        'CHL-1' => 'Chemistry Laboratory',
        'GYM' => 'Gymnasium',
        'COURT' => 'Covered Court',
    ];

    /**
     * General education subjects. Subjects belong to one college, so every
     * college gets its own copy of these.
     *
     * Subject tuple: [code, title, lecture units, laboratory units, options].
     * Options: `kind` (pe | nstp | project), `lab` (lab room group),
     * `tba` (offered without a teacher or schedule yet).
     *
     * @var list<array{0: string, 1: string, 2: int, 3: int, 4?: array{kind?: string, lab?: string, tba?: bool}}>
     */
    public const GENERAL_EDUCATION = [
        ['GE 1', 'Understanding the Self', 3, 0],
        ['GE 4', 'Mathematics in the Modern World', 3, 0],
        ['GE 5', 'Purposive Communication', 3, 0],
        ['GE 6', 'Art Appreciation', 3, 0],
        ['GE 8', 'Ethics', 3, 0],
        ['GE 9', 'Life and Works of Rizal', 3, 0],
        ['PE 1', 'Movement Competency Training', 2, 0, ['kind' => 'pe']],
        ['PE 3', 'Individual and Dual Sports', 2, 0, ['kind' => 'pe']],
        ['NSTP 1', 'National Service Training Program 1', 3, 0, ['kind' => 'nstp']],
        // 2nd semester
        ['GE 2', 'Readings in Philippine History', 3, 0],
        ['GE 3', 'The Contemporary World', 3, 0],
        ['GE 7', 'Science, Technology and Society', 3, 0],
        ['GEE 1', 'Living in the IT Era', 3, 0],
        ['GEE 2', 'Gender and Society', 3, 0],
        ['GEE 3', 'Philippine Popular Culture', 3, 0],
        ['GEE 4', 'Great Books', 3, 0],
        ['PE 2', 'Rhythmic Activities', 2, 0, ['kind' => 'pe']],
        ['PE 4', 'Team Sports', 2, 0, ['kind' => 'pe']],
        ['NSTP 2', 'National Service Training Program 2', 3, 0, ['kind' => 'nstp']],
    ];

    /** General education codes per year level, first semester. */
    public const GENERAL_EDUCATION_BY_YEAR = [
        1 => ['GE 1', 'GE 4', 'PE 1', 'NSTP 1'],
        2 => ['GE 5', 'GE 6', 'PE 3'],
        3 => ['GE 8'],
        4 => ['GE 9'],
    ];

    /**
     * The upcoming 2nd semester's subjects per year level: general
     * education only, offered without a teacher or schedule yet (the
     * dean's to fill in).
     */
    public const SECOND_SEMESTER_BY_YEAR = [
        1 => ['GE 2', 'PE 2', 'NSTP 2'],
        2 => ['GE 3', 'GEE 1', 'PE 4'],
        3 => ['GE 7', 'GEE 2'],
        4 => ['GEE 3', 'GEE 4'],
    ];

    /**
     * The one curriculum line left out of a section, so its page offers
     * "Add 1 from the curriculum": the upcoming term's BSCS 1-B lacks NSTP 2.
     */
    public const CURRICULUM_GAP = ['program' => 'BSCS', 'section' => '1-B', 'subject' => 'NSTP 2'];

    /**
     * The college whose upcoming 2nd-semester sections aren't made yet, so
     * its dean is offered "Copy sections" from the 1st semester.
     */
    public const UNCOPIED_COLLEGE = 'COE';

    /**
     * How far each other college is with the upcoming term, for the admin's
     * readiness panel: READY_COLLEGE has a teacher and classes for every
     * subject; PARTLY_READY_COLLEGE has teachers for most and no classes yet.
     */
    public const READY_COLLEGE = 'COEng';

    public const PARTLY_READY_COLLEGE = 'COS';

    /** Learners per college admitted a few days ago for next semester, who haven't signed in yet. */
    public const NEWLY_IMPORTED_PER_COLLEGE = 2;

    /** Days after a class that its teacher may still change marks, in the current term (null = no limit). */
    public const TEACHER_EDIT_DAYS = 7;

    /**
     * Programs per college, with their major subjects per year level.
     * A subject listed under two programs of one college is one subject row
     * offered to both.
     *
     * @var array<string, array<string, array{name: string, years: array<int, list<array{0: string, 1: string, 2: int, 3: int, 4?: array{kind?: string, lab?: string, tba?: bool}}>>}>>
     */
    public const PROGRAMS = [
        'COS' => [
            'BSCS' => [
                'name' => 'Bachelor of Science in Computer Science',
                'years' => [
                    1 => [
                        ['CS 101', 'Introduction to Computing', 2, 1, ['lab' => 'computer']],
                        ['CS 102', 'Computer Programming 1', 2, 1, ['lab' => 'computer']],
                    ],
                    2 => [
                        ['CS 201', 'Data Structures and Algorithms', 2, 1, ['lab' => 'computer']],
                        ['CS 202', 'Discrete Structures 2', 3, 0],
                        ['CS 203', 'Object-Oriented Programming', 2, 1, ['lab' => 'computer']],
                    ],
                    3 => [
                        ['CS 301', 'Automata Theory and Formal Languages', 3, 0],
                        ['CS 302', 'Software Engineering 1', 3, 0],
                        ['CS 303', 'Operating Systems', 2, 1, ['lab' => 'computer']],
                        ['CS 304', 'Information Assurance and Security', 3, 0],
                    ],
                    4 => [
                        ['CS 401', 'CS Thesis 2', 3, 0, ['kind' => 'project']],
                        ['CS 402', 'Social Issues and Professional Practice', 3, 0],
                        ['CS 403', 'Human-Computer Interaction', 2, 1, ['lab' => 'computer']],
                        ['CS 404', 'CS Elective 1: Machine Learning', 3, 0, ['tba' => true]],
                    ],
                ],
            ],
            'BSBio' => [
                'name' => 'Bachelor of Science in Biology',
                'years' => [
                    1 => [
                        ['BIO 101', 'General Biology', 2, 1, ['lab' => 'science']],
                        ['CHEM 101', 'General Chemistry', 2, 1, ['lab' => 'science']],
                    ],
                    2 => [
                        ['BIO 201', 'Systematics', 2, 1, ['lab' => 'science']],
                        ['BIO 202', 'Cell and Molecular Biology', 2, 1, ['lab' => 'science']],
                        ['BIO 203', 'Biostatistics', 3, 0],
                    ],
                    3 => [
                        ['BIO 301', 'Genetics', 2, 1, ['lab' => 'science']],
                        ['BIO 302', 'Microbiology', 2, 1, ['lab' => 'science']],
                        ['BIO 303', 'Ecology', 3, 0],
                        ['BIO 304', 'Developmental Biology', 3, 0],
                    ],
                    4 => [
                        ['BIO 401', 'Undergraduate Thesis 2', 3, 0, ['kind' => 'project']],
                        ['BIO 402', 'Evolutionary Biology', 3, 0],
                        ['BIO 403', 'Seminar in Biology', 3, 0],
                    ],
                ],
            ],
        ],
        'COE' => [
            'BEEd' => [
                'name' => 'Bachelor of Elementary Education',
                'years' => [
                    1 => [
                        ['EDUC 101', 'The Child and Adolescent Learners and Learning Principles', 3, 0],
                        ['EDUC 102', 'The Teaching Profession', 3, 0],
                    ],
                    2 => [
                        ['EDUC 201', 'Facilitating Learner-Centered Teaching', 3, 0],
                        ['EDUC 202', 'Technology for Teaching and Learning 1', 2, 1, ['lab' => 'multimedia']],
                        ['BEED 201', 'Teaching Science in the Elementary Grades', 3, 0],
                    ],
                    3 => [
                        ['EDUC 301', 'Assessment in Learning 1', 3, 0],
                        ['BEED 301', 'Teaching Mathematics in the Primary Grades', 3, 0],
                        ['BEED 302', 'Teaching Social Studies in the Elementary Grades', 3, 0],
                        ['BEED 303', 'Teaching Music in the Elementary Grades', 3, 0],
                    ],
                    4 => [
                        ['EDUC 401', 'Field Study 1', 3, 0],
                        ['EDUC 402', 'Field Study 2', 3, 0],
                        ['BEED 401', 'Research in Education', 3, 0, ['kind' => 'project']],
                    ],
                ],
            ],
            'BSEd-MATH' => [
                'name' => 'Bachelor of Secondary Education major in Mathematics',
                'years' => [
                    1 => [
                        ['EDUC 101', 'The Child and Adolescent Learners and Learning Principles', 3, 0],
                        ['EDUC 102', 'The Teaching Profession', 3, 0],
                    ],
                    2 => [
                        ['EDUC 201', 'Facilitating Learner-Centered Teaching', 3, 0],
                        ['EDUC 202', 'Technology for Teaching and Learning 1', 2, 1, ['lab' => 'multimedia']],
                        ['MATH 201', 'Elementary Statistics and Probability', 3, 0],
                    ],
                    3 => [
                        ['EDUC 301', 'Assessment in Learning 1', 3, 0],
                        ['MATH 301', 'Abstract Algebra', 3, 0],
                        ['MATH 302', 'Advanced Calculus', 3, 0],
                        ['MATH 303', 'Problem Solving, Mathematical Investigation and Modeling', 3, 0],
                    ],
                    4 => [
                        ['EDUC 401', 'Field Study 1', 3, 0],
                        ['EDUC 402', 'Field Study 2', 3, 0],
                        ['MATH 401', 'Research in Mathematics Education', 3, 0, ['kind' => 'project']],
                        ['MATH 402', 'Mathematics Elective: History of Mathematics', 3, 0, ['tba' => true]],
                    ],
                ],
            ],
        ],
        'COEng' => [
            'BSCE' => [
                'name' => 'Bachelor of Science in Civil Engineering',
                'years' => [
                    1 => [
                        ['MATH 101', 'Differential Calculus', 3, 0],
                        ['CHEM 101', 'Chemistry for Engineers', 3, 1, ['lab' => 'chemistry']],
                    ],
                    2 => [
                        ['MATH 201', 'Differential Equations', 3, 0],
                        ['ENGG 201', 'Computer-Aided Drafting', 0, 1, ['lab' => 'drafting']],
                        ['CE 201', 'Statics of Rigid Bodies', 3, 0],
                    ],
                    3 => [
                        ['CE 301', 'Structural Theory', 3, 0],
                        ['CE 302', 'Hydraulics', 2, 1, ['lab' => 'engineering']],
                        ['CE 303', 'Geotechnical Engineering 1', 2, 1, ['lab' => 'engineering']],
                        ['CE 304', 'Construction Materials and Testing', 2, 1, ['lab' => 'engineering']],
                    ],
                    4 => [
                        ['CE 401', 'Reinforced Concrete Design', 3, 0],
                        ['CE 402', 'Highway and Railroad Engineering', 3, 0],
                        ['CE 403', 'CE Project 1', 3, 0, ['kind' => 'project']],
                    ],
                ],
            ],
            'BSEE' => [
                'name' => 'Bachelor of Science in Electrical Engineering',
                'years' => [
                    1 => [
                        ['MATH 101', 'Differential Calculus', 3, 0],
                        ['CHEM 101', 'Chemistry for Engineers', 3, 1, ['lab' => 'chemistry']],
                    ],
                    2 => [
                        ['MATH 201', 'Differential Equations', 3, 0],
                        ['ENGG 201', 'Computer-Aided Drafting', 0, 1, ['lab' => 'drafting']],
                        ['EE 201', 'Electrical Circuits 1', 3, 1, ['lab' => 'engineering']],
                    ],
                    3 => [
                        ['EE 301', 'Electrical Machines 1', 2, 1, ['lab' => 'engineering']],
                        ['EE 302', 'Electronic Circuits', 2, 1, ['lab' => 'engineering']],
                        ['EE 303', 'Electromagnetics', 3, 0],
                        ['EE 304', 'Power System Analysis', 3, 0],
                    ],
                    4 => [
                        ['EE 401', 'Power Plant Engineering', 3, 0],
                        ['EE 402', 'Electrical Systems Design', 2, 1, ['lab' => 'engineering']],
                        ['EE 403', 'EE Project 1', 3, 0, ['kind' => 'project']],
                        ['EE 404', 'EE Elective 1: Renewable Energy Systems', 3, 0, ['tba' => true]],
                    ],
                ],
            ],
        ],
    ];

    /**
     * Learners per program and year level, and the capacity of each of that
     * year's sections (null = no limit). First years get two sections, so
     * auto-sectioning has a choice; where the learners outnumber the seats
     * (BSCS, BEEd, BSCE first years), the rest stay unsectioned for the dean.
     *
     * @var array<int, array{learners: int, sections: int, capacity: int|null}>
     */
    public const YEAR_LEVELS = [
        1 => ['learners' => 58, 'sections' => 2, 'capacity' => 35],
        2 => ['learners' => 32, 'sections' => 1, 'capacity' => 40],
        3 => ['learners' => 28, 'sections' => 1, 'capacity' => null],
        4 => ['learners' => 25, 'sections' => 1, 'capacity' => null],
    ];

    /** Programs whose first-year sections overflow: capacity 30, 62 learners. */
    public const OVERFLOWING_PROGRAMS = ['BSCS', 'BEEd', 'BSCE'];

    /** Teachers per college: 'P' (permanent) or 'T' (temporary) employment. */
    public const TEACHERS_PER_COLLEGE = 16;

    /** Most hours a week the timetabler gives one teacher. */
    public const MAX_TEACHER_HOURS = 27;

    /**
     * Calendar events of the current term (and the enrollment week before
     * the next). `college` null = campus-wide.
     * A suspension with `suspends_from` covers only the classes starting
     * from then on; `memo_number` comes with a placeholder memo PDF, and
     * `entered_at` backdates when it was entered. `programs` (codes) and
     * `year_levels` narrow a suspension to the sections that match both.
     *
     * @var list<array{college: string|null, title: string, starts_on: string, ends_on: string, suspends_classes: bool, description?: string, suspends_from?: string, memo_number?: string, entered_at?: string, programs?: list<string>, year_levels?: list<int>}>
     */
    public const CALENDAR = [
        ['college' => null, 'title' => 'Ninoy Aquino Day', 'starts_on' => '2026-08-21', 'ends_on' => '2026-08-21', 'suspends_classes' => true],
        ['college' => null, 'title' => 'National Heroes Day', 'starts_on' => '2026-08-31', 'ends_on' => '2026-08-31', 'suspends_classes' => true],
        ['college' => 'COEng', 'title' => 'Engineering Week', 'starts_on' => '2026-09-14', 'ends_on' => '2026-09-17', 'suspends_classes' => false, 'description' => 'Exhibits and seminars; classes continue.'],
        ['college' => 'COEng', 'title' => 'Engineering Week culminating day', 'starts_on' => '2026-09-18', 'ends_on' => '2026-09-18', 'suspends_classes' => true],
        ['college' => null, 'title' => 'Class suspension: Typhoon Signal No. 2', 'starts_on' => '2026-09-24', 'ends_on' => '2026-09-24', 'suspends_classes' => true, 'description' => 'Classes at all levels suspended by the provincial government.'],
        ['college' => null, 'title' => 'Midterm examinations', 'starts_on' => '2026-09-28', 'ends_on' => '2026-10-03', 'suspends_classes' => false],
        // Announced mid-afternoon: a couple of classes after 13:00 were already
        // marked (see AttendanceSeeder), so those marks stay but don't count.
        ['college' => 'COS', 'title' => 'Science Fair awarding ceremony', 'starts_on' => '2026-10-09', 'ends_on' => '2026-10-09', 'suspends_classes' => true, 'suspends_from' => '13:00', 'memo_number' => 'COS Memo No. 2026-031', 'entered_at' => '2026-10-09 15:30', 'description' => 'Afternoon classes suspended for the awarding ceremony at the gymnasium.'],
        ['college' => 'COE', 'title' => "World Teachers' Day celebration", 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-05', 'suspends_classes' => true],
        // Narrowed: only BSCE 4-A's three Tuesday classes are off; the rest of COEng meets.
        ['college' => 'COEng', 'title' => 'BSCE 4th-year field trip', 'starts_on' => '2026-10-13', 'ends_on' => '2026-10-13', 'suspends_classes' => true, 'programs' => ['BSCE'], 'year_levels' => [4], 'description' => 'Site visit to the provincial bridge project for CE 401 and CE 402.'],
        ['college' => null, 'title' => "All Saints' Day", 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01', 'suspends_classes' => true],
        ['college' => null, 'title' => "All Souls' Day", 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-02', 'suspends_classes' => true],
        ['college' => 'COS', 'title' => 'Science and Technology Week', 'starts_on' => '2026-11-23', 'ends_on' => '2026-11-27', 'suspends_classes' => false],
        ['college' => null, 'title' => 'Bonifacio Day', 'starts_on' => '2026-11-30', 'ends_on' => '2026-11-30', 'suspends_classes' => true],
        ['college' => null, 'title' => 'Feast of the Immaculate Conception', 'starts_on' => '2026-12-08', 'ends_on' => '2026-12-08', 'suspends_classes' => true],
        // Coming up in the next month or two.
        ['college' => 'COE', 'title' => 'Demonstration teaching week', 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-13', 'suspends_classes' => false, 'description' => 'Fourth years give demonstration lessons at the laboratory school; classes continue.'],
        ['college' => null, 'title' => 'University Foundation Day', 'starts_on' => '2026-11-16', 'ends_on' => '2026-11-16', 'suspends_classes' => true],
        ['college' => 'COEng', 'title' => 'Board exam review orientation', 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-20', 'suspends_classes' => true, 'suspends_from' => '13:00', 'year_levels' => [4], 'description' => 'Fourth years attend the review center orientation in the afternoon.'],
        ['college' => 'COS', 'title' => 'Undergraduate research colloquium', 'starts_on' => '2026-12-04', 'ends_on' => '2026-12-04', 'suspends_classes' => true, 'year_levels' => [4], 'description' => 'Fourth years present their theses.'],
        ['college' => null, 'title' => 'Christmas lantern parade', 'starts_on' => '2026-12-11', 'ends_on' => '2026-12-11', 'suspends_classes' => true, 'suspends_from' => '15:00'],
        ['college' => null, 'title' => 'Final examinations', 'starts_on' => '2026-12-14', 'ends_on' => '2026-12-18', 'suspends_classes' => false],
        ['college' => null, 'title' => '2nd semester enrollment period', 'starts_on' => '2027-01-04', 'ends_on' => '2027-01-08', 'suspends_classes' => false, 'description' => 'Registration at the registrar\'s office for the 2nd semester.'],
    ];

    /**
     * Whole weeks from ANCHOR's week to this week (negative before it).
     */
    public static function weeksShifted(): int
    {
        $thisWeek = CarbonImmutable::today()->startOfWeek(CarbonInterface::MONDAY);

        return (int) round(CarbonImmutable::parse(self::ANCHOR)->diffInDays($thisWeek) / 7);
    }

    /**
     * A date (or date and time) written here, moved to this week's frame.
     */
    public static function date(string $written): CarbonImmutable
    {
        return CarbonImmutable::parse($written)->addWeeks(self::weeksShifted());
    }

    /**
     * A term written here, with its dates moved and its school year label
     * following once the move is half a year or more (all labels together,
     * so last year, this year and next keep their order).
     *
     * @param  array{school_year: string, semester: string, starts_on: string, ends_on: string}  $term
     * @return array{school_year: string, semester: string, starts_on: string, ends_on: string}
     */
    public static function term(array $term): array
    {
        $years = (int) round(self::weeksShifted() * 7 / 365.2425);
        [$from, $to] = array_map('intval', explode('-', $term['school_year']));

        return [
            'school_year' => ($from + $years).'-'.($to + $years),
            'semester' => $term['semester'],
            'starts_on' => self::date($term['starts_on'])->toDateString(),
            'ends_on' => self::date($term['ends_on'])->toDateString(),
        ];
    }

    /** The seeded current term (TERM). */
    public static function currentTerm(): Term
    {
        return self::seeded(self::TERM);
    }

    /** The seeded upcoming term (NEXT_TERM). */
    public static function nextTerm(): Term
    {
        return self::seeded(self::NEXT_TERM);
    }

    /**
     * @param  array{school_year: string, semester: string, starts_on: string, ends_on: string}  $term
     */
    private static function seeded(array $term): Term
    {
        $moved = self::term($term);

        return Term::where('school_year', $moved['school_year'])->where('semester', $moved['semester'])->sole();
    }
}

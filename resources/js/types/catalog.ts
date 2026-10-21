import type { UserRole } from './auth';

export type College = {
    id: number;
    code: string;
    name: string;
    programs_count?: number;
    subjects_count?: number;
    staff_count?: number;
    learner_count?: number;
};

export type Program = {
    id: number;
    college_id: number;
    code: string;
    name: string;
    learners_count?: number;
    sections_count?: number;
};

export type Subject = {
    id: number;
    college_id: number;
    code: string;
    title: string;
    lecture_units: string;
    laboratory_units: string;
    college?: Pick<College, 'id' | 'code'>;
    offerings_count?: number;
};

export type RoomType = 'lecture' | 'laboratory' | 'venue';

export type Room = {
    id: number;
    code: string;
    name: string | null;
    /** The name if set, else the code: how to refer to the room. */
    label: string;
    building: string | null;
    floor: number | null;
    capacity: number | null;
    room_type: RoomType;
    is_active: boolean;
    schedules_count?: number;
};

/** Laravel's LengthAwarePaginator, as Inertia receives it. */
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

export type Semester = 'first' | 'second' | 'summer';

/** Upcoming, in session and ended come from the dates; closed is set by the admin. */
export type TermPhase = 'upcoming' | 'in_session' | 'ended' | 'closed';

export type AttendancePolicy = {
    lates_per_absence: number;
    drop_at_absences: number;
    warn_at_absences: number[];
};

export type Term = {
    id: number;
    school_year: string;
    semester: Semester;
    /** Y-m-d */
    starts_on: string;
    /** Y-m-d */
    ends_on: string;
    /** "1st Semester 2026-2027" */
    label: string;
    phase?: TermPhase;
    closed_at?: string | null;
    attendance_policy?: AttendancePolicy | null;
    sections_count?: number;
    term_registrations_count?: number;
};

export type CalendarEvent = {
    id: number;
    title: string;
    description: string | null;
    /** Y-m-d */
    starts_on: string;
    /** Y-m-d */
    ends_on: string;
    suspends_classes: boolean;
    /** "HH:MM": a suspension only catches classes starting in [from, until). */
    suspends_from: string | null;
    suspends_until: string | null;
    /** Narrowed to sections of these programs / year levels (empty = all). */
    program_ids: number[];
    programs: string[];
    year_levels: number[];
    memo_number: string | null;
    memo_name: string | null;
    memo_url: string | null;
    /** College code, or null for campus-wide. */
    college: string | null;
    can_edit: boolean;
};

export type DutyStatus = 'active' | 'on_leave';
export type LearnerStatus = 'active' | 'inactive' | 'graduated' | 'dropped_out';

/** A user as the account screens list them. */
export type Account = {
    id: number;
    role: UserRole;
    college_id: number | null;
    is_active: boolean;
    last_name: string;
    first_name: string;
    middle_name: string | null;
    suffix: string | null;
    full_name: string;
    email: string;
    college?: Pick<College, 'id' | 'code'> | null;
    teacher?: {
        id: number;
        employee_number: string;
        duty_status: DutyStatus;
    } | null;
    learner?: { id: number; student_number: string } | null;
};

export type LearnerRecord = {
    id: number;
    user_id: number;
    program_id: number;
    student_number: string;
    year_level: number;
    status: LearnerStatus;
    user: Account;
    program: Pick<Program, 'id' | 'college_id' | 'code'> & {
        college: Pick<College, 'id' | 'code'>;
    };
};

/** Flashed once after an account is made or sent a new setup link. */
export type SetupLink = {
    name: string;
    email: string;
    url: string;
    days: number;
};

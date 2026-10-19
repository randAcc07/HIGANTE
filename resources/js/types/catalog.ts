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
    /** College code, or null for campus-wide. */
    college: string | null;
    can_edit: boolean;
};

import type { Room, Subject } from './catalog';

export type Weekday = 1 | 2 | 3 | 4 | 5 | 6 | 7;

/** One class on the weekly grid, as the server shapes it. */
export type ClassBlock = {
    id: number;
    subject_offering_id: number;
    batch_id: number | null;
    weekday: Weekday;
    /** "HH:MM" */
    start: string;
    end: string;
    mode: 'onsite' | 'online';
    room_id: number | null;
    teacher_id: number;
    subject: string;
    section: string;
    batch: string | null;
    room: string | null;
    teacher: string;
    warnings?: string[];
    has_attendance?: boolean;
    section_id?: number;
};

/** Another college's booking of a shared room: no details. */
export type BusyBlock = {
    id: number;
    weekday: Weekday;
    start: string;
    end: string;
    busy: string;
};

export type TeacherOption = { id: number; name: string; on_leave: boolean };

export type RoomOption = Pick<
    Room,
    'id' | 'code' | 'name' | 'building' | 'capacity' | 'room_type'
>;

export type Batch = {
    id: number;
    subject_offering_id: number;
    name: string;
    enrollments_count?: number;
    schedules_count?: number;
};

export type Offering = {
    id: number;
    section_id: number;
    subject_id: number;
    teacher_id: number | null;
    subject: Pick<
        Subject,
        'id' | 'code' | 'title' | 'lecture_units' | 'laboratory_units'
    >;
    teacher: { id: number; user: { full_name: string } } | null;
    batches: Batch[];
    enrollments_count: number;
};

/** What the grid draws. tone picks a colour; "busy" is greyed out. */
export type GridBlock = {
    key: string;
    id: number;
    weekday: Weekday;
    start: string;
    end: string;
    title: string;
    lines: string[];
    tone: number | 'busy';
    warning?: boolean;
};

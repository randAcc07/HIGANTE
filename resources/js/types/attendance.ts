export type AttendanceStatus = 'present' | 'late' | 'absent';

/** One class meeting on a day's sheet (the teacher's day or the dean's). */
export type DayClass = {
    schedule_id: number;
    subject: string;
    title: string;
    /** "BSCS 1-A". */
    section: string;
    batch: string | null;
    room: string | null;
    teacher: string;
    start: string;
    end: string;
    /** Why attendance can't be taken that day (suspended, not yet), if so. */
    refusal: string | null;
    listed: number;
    /** Exceptions marked; everyone else on the list counts as present. */
    late: number;
    absent: number;
};

/** A learner on a class list. */
export type ClassListLearner = {
    enrollment_id: number;
    name: string;
    student_number: string;
    status: AttendanceStatus | null;
    /** An approved excuse decides this absence; the mark is locked. */
    excused: boolean;
    recorded_by: string | null;
    recorded_at: string | null;
    standing: 'for_dropping' | 'dropped' | null;
    /** Highest warning count reached in this class; 0 = none. */
    warning_level: number;
};

import type { Subject } from './catalog';

/** A subject offering as the dean's learner screens show it. */
export type PlacementOffering = {
    id: number;
    subject: Pick<
        Subject,
        'id' | 'code' | 'title' | 'lecture_units' | 'laboratory_units'
    >;
    /** "BSCS 2-A". */
    section: string;
    batches: { id: number; name: string }[];
};

export type PlacementEnrollment = {
    id: number;
    status: 'enrolled' | 'for_dropping' | 'dropped';
    batch_id: number | null;
    attendance_count: number;
    offering: PlacementOffering;
};

/** One learner on a section's batch roster. */
export type RosterRow = {
    learner_id: number;
    registration_id: number | null;
    name: string;
    student_number: string;
    is_irregular: boolean;
    /** Their own section's name when they come from another section. */
    from_section: string | null;
    /** Offering id → their enrollment in it. */
    enrollments: Record<number, { id: number; batch_id: number | null }>;
};

export type ExcuseReason = 'medical' | 'illness' | 'school_activity' | 'other';

export type ExcuseStatus = 'pending' | 'approved' | 'rejected' | 'revoked';

/** An excuse as the learner's and the dean's pages show it. */
export type Excuse = {
    id: number;
    date_from: string;
    date_to: string;
    reason: ExcuseReason;
    remarks: string | null;
    status: ExcuseStatus;
    submitted_on: string | null;
    /** Days past the submission deadline (0 = on time). */
    days_late: number;
    reviewed_on: string | null;
    review_remarks: string | null;
    revoked_on: string | null;
    revoke_remarks: string | null;
    /** Absences linked to it (excused); null where not counted. */
    excused: number | null;
    documents: { id: number; name: string; size: number; url: string }[];
};

export type ExcuseLearner = {
    name: string;
    student_number: string;
    program: string;
    registration_id: number | null;
};

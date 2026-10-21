export type ExcuseReason = 'medical' | 'illness' | 'school_activity' | 'other';

/** Summarized from the excuse's per-class decisions. */
export type ExcuseStatus =
    | 'pending'
    | 'approved'
    | 'partial'
    | 'rejected'
    | 'revoked';

export type DecisionStatus = 'pending' | 'approved' | 'declined' | 'revoked';

/** One class's decision on an excuse. */
export type ExcuseDecision = {
    id: number;
    subject: { code: string; title: string };
    /** "BSCS 1-A". */
    section: string;
    status: DecisionStatus;
    remarks: string | null;
    decided_by: string | null;
    decided_on: string | null;
    revoke_remarks: string | null;
    revoked_on: string | null;
};

/** An excuse as the learner's, teachers' and dean's pages show it. */
export type Excuse = {
    id: number;
    date_from: string;
    date_to: string;
    reason: ExcuseReason;
    remarks: string | null;
    status: ExcuseStatus;
    submitted_on: string | null;
    /** The teacher who filed it for the learner, if not the learner. */
    filed_by: string | null;
    /** Days past the submission deadline (0 = on time). */
    days_late: number;
    documents: { id: number; name: string; size: number; url: string }[];
    decisions: ExcuseDecision[];
};

export type ExcuseLearner = {
    name: string;
    student_number: string;
    program: string;
    registration_id?: number | null;
};

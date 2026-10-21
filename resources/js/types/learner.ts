/** A learner's own class and their standing in it. */
export type LearnerClass = {
    enrollment_id: number;
    subject: { code: string; title: string };
    section: string;
    batch: string | null;
    teacher: string | null;
    status: 'enrolled' | 'for_dropping' | 'dropped';
    warning_level: number;
    flagged_on: string | null;
    dropped_on: string | null;
    drop_reason: 'absences' | 'withdrawn' | 'removed' | null;
};

export type StandingCounts = {
    absences: number;
    lates: number;
    excused: number;
    equivalent: number;
};

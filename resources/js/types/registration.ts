import type { LearnerRecord, Semester, TermPhase } from './catalog';

export type RegistrationStatus = 'registered' | 'withdrawn';

export type RegistrationTermOption = {
    id: number;
    label: string;
    phase: TermPhase;
    semester: Semester;
};

/** A learner on the list to register, with what the server proposes. */
export type RegistrationCandidate = {
    learner: LearnerRecord;
    year_level: number;
    is_irregular: boolean;
    ticked: boolean;
    previous: {
        term: string;
        year_level: number;
        section: string | null;
        status: RegistrationStatus;
    } | null;
};

export type RegistrationRow = {
    id: number;
    learner: LearnerRecord;
    year_level: number;
    section: { id: number; name: string; capacity: number | null } | null;
    is_irregular: boolean;
    status: RegistrationStatus;
    registered_on: string | null;
    registered_by: string | null;
};

import type { ClassBlock, Weekday } from './scheduling';

/** The current term, beside the dashboard's title. */
export type TermBadge = {
    label: string;
    phase: 'upcoming' | 'in_session' | 'ended' | 'closed';
    /** Week of the term today, while in session. */
    week: number | null;
    weeks: number;
    /** Days until it starts, while upcoming. */
    starts_in: number | null;
};

/** A class on its date in a dated week. */
export type WeekClass = ClassBlock & {
    program: string;
    date: string;
    /** The holiday or suspension that cancels it that day. */
    cancelled: string | null;
    /** The substitute covering it that day. */
    cover: string | null;
    /** Whether the viewer is the one covering it. */
    covering: boolean;
};

/** One dated week (Mon–Sat) of classes. */
export type DatedWeek = {
    monday: string;
    previous: string | null;
    next: string | null;
    days: {
        weekday: Weekday;
        date: string;
        events: { title: string; suspends: boolean }[];
    }[];
    blocks: WeekClass[];
};

/** A holiday or suspension coming up that cancels some of the viewer's classes. */
export type UpcomingSuspension = {
    title: string;
    starts_on: string;
    ends_on: string;
    from: string | null;
    until: string | null;
    classes: number;
};

import { parseDate } from './dates';

/** "2026-10-20" shifted by whole days, as "Y-m-d" again. */
export function addDays(value: string, days: number): string {
    const date = parseDate(value);
    date.setDate(date.getDate() + days);

    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');
}

/** ISO weekday (1 = Monday … 7 = Sunday) of a "Y-m-d" date. */
export function isoWeekday(value: string): number {
    return parseDate(value).getDay() || 7;
}

/** Today as "Y-m-d", in the viewer's own time zone. */
export function today(): string {
    const now = new Date();

    return [
        now.getFullYear(),
        String(now.getMonth() + 1).padStart(2, '0'),
        String(now.getDate()).padStart(2, '0'),
    ].join('-');
}

/** Whole days from one "Y-m-d" date to another (negative when earlier). */
export function daysBetween(from: string, to: string): number {
    return Math.round(
        (parseDate(to).getTime() - parseDate(from).getTime()) / 86_400_000,
    );
}

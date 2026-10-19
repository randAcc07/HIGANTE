/**
 * Dates without a time ("2026-08-10") are parsed as local dates, so no
 * timezone can move them to the day before.
 */
export function parseDate(value: string): Date {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day);
}

export function formatDate(
    value: string,
    options: Intl.DateTimeFormatOptions = {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    },
): string {
    return parseDate(value).toLocaleDateString(undefined, options);
}

/** "Aug 10 – Dec 18, 2026", or a single date when both ends match. */
export function formatDateRange(start: string, end: string): string {
    if (start === end) {
        return formatDate(start, {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });
    }

    const from = parseDate(start);
    const to = parseDate(end);
    const sameYear = from.getFullYear() === to.getFullYear();

    return `${from.toLocaleDateString(undefined, sameYear ? { month: 'short', day: 'numeric' } : { month: 'short', day: 'numeric', year: 'numeric' })} – ${to.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}`;
}

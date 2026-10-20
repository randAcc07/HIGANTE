import { describe, expect, it } from 'vite-plus/test';
import { formatDate, formatDateRange, parseDate } from './dates';

// The tests run in a zone behind UTC (see vite.config.ts), where reading
// "2026-08-10" as UTC midnight would land on August 9.
describe('date helpers', () => {
    it('reads a date-only value as that local day', () => {
        const date = parseDate('2026-08-10');

        expect([date.getFullYear(), date.getMonth(), date.getDate()]).toEqual([
            2026, 7, 10,
        ]);
        expect(new Date('2026-08-10').getDate()).toBe(9);
    });

    it('formats the same day the value names', () => {
        expect(formatDate('2026-08-10', { day: 'numeric' })).toBe(
            new Date(2026, 7, 10).toLocaleDateString(undefined, {
                day: 'numeric',
            }),
        );
    });

    it('shortens ranges within a year and shows one day once', () => {
        const range = formatDateRange('2026-08-10', '2026-12-18');

        expect(range.split(' – ')).toHaveLength(2);
        expect(range.match(/2026/g)).toHaveLength(1);
        expect(
            formatDateRange('2026-12-18', '2027-01-08').match(/202[67]/g),
        ).toHaveLength(2);
        expect(formatDateRange('2026-08-10', '2026-08-10')).not.toContain('–');
    });
});

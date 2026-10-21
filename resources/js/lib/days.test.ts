import { describe, expect, it } from 'vite-plus/test';
import { addDays, daysBetween, isoWeekday } from './days';

describe('day helpers', () => {
    it('steps across month and year ends', () => {
        expect(addDays('2026-10-20', 1)).toBe('2026-10-21');
        expect(addDays('2026-10-31', 1)).toBe('2026-11-01');
        expect(addDays('2027-01-01', -1)).toBe('2026-12-31');
        expect(addDays('2028-02-28', 1)).toBe('2028-02-29');
    });

    it('numbers weekdays from Monday', () => {
        expect(isoWeekday('2026-10-19')).toBe(1);
        expect(isoWeekday('2026-10-24')).toBe(6);
        expect(isoWeekday('2026-10-25')).toBe(7);
    });

    it('counts whole days between dates, across a clock change', () => {
        expect(daysBetween('2026-10-20', '2026-10-27')).toBe(7);
        expect(daysBetween('2026-10-27', '2026-10-20')).toBe(-7);
        // The test zone (America/Los_Angeles) leaves summer time on Nov 1.
        expect(daysBetween('2026-10-31', '2026-11-02')).toBe(2);
    });
});

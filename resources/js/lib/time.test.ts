import { describe, expect, it } from 'vite-plus/test';
import { formatTime, fromMinutes, toMinutes } from './time';

describe('time helpers', () => {
    it('converts between "HH:MM" and minutes', () => {
        expect(toMinutes('00:00')).toBe(0);
        expect(toMinutes('08:30')).toBe(510);
        expect(toMinutes('20:00')).toBe(1200);
        expect(fromMinutes(510)).toBe('08:30');
        expect(fromMinutes(65)).toBe('01:05');

        for (const time of ['07:00', '12:30', '19:30']) {
            expect(fromMinutes(toMinutes(time))).toBe(time);
        }
    });

    it('formats 12-hour times around noon and midnight', () => {
        expect(formatTime('00:30')).toBe('12:30 AM');
        expect(formatTime('08:05')).toBe('8:05 AM');
        expect(formatTime('12:00')).toBe('12:00 PM');
        expect(formatTime('13:30')).toBe('1:30 PM');
    });
});

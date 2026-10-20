import { describe, expect, it } from 'vite-plus/test';
import { newcomerCounts } from './batchSplit';

const slot = (name: string, capacity: number | null, current = 0) => ({
    name,
    capacity,
    current,
});

describe('newcomerCounts', () => {
    it('splits evenly when the labs have no known seats', () => {
        expect(
            newcomerCounts([slot('AA', null), slot('AB', null)], 33),
        ).toEqual({
            counts: [17, 16],
            over: [],
        });
    });

    it('keeps each batch within its lab, sending the rest where there is room', () => {
        expect(newcomerCounts([slot('AA', 12), slot('AB', 30)], 33)).toEqual({
            counts: [12, 21],
            over: [],
        });
    });

    it('overfills the last batch only when every lab is full', () => {
        expect(newcomerCounts([slot('AA', 15), slot('AB', 15)], 33)).toEqual({
            counts: [15, 18],
            over: ['AB'],
        });
    });

    it('evens out around learners who already have a batch', () => {
        expect(
            newcomerCounts([slot('AA', null, 20), slot('AB', null, 0)], 4),
        ).toEqual({ counts: [0, 4], over: [] });
        expect(
            newcomerCounts([slot('AA', null, 16), slot('AB', null, 14)], 3),
        ).toEqual({ counts: [1, 2], over: [] });
    });

    it('counts seats taken by learners who stay', () => {
        // AB's 5 seats fill first; AA has only 2 left of 20, so 3 run over in AB.
        expect(
            newcomerCounts([slot('AA', 20, 18), slot('AB', 5, 0)], 10),
        ).toEqual({
            counts: [2, 8],
            over: ['AB'],
        });
    });

    it('places everyone it is given', () => {
        for (const newcomers of [0, 1, 7, 40, 61]) {
            const { counts } = newcomerCounts(
                [slot('AA', 20, 3), slot('AB', 25), slot('AC', null, 1)],
                newcomers,
            );

            expect(counts.reduce((sum, count) => sum + count, 0)).toBe(
                newcomers,
            );
            expect(counts.every((count) => count >= 0)).toBe(true);
        }
    });

    it('handles a section without batches', () => {
        expect(newcomerCounts([], 5)).toEqual({ counts: [], over: [] });
    });
});

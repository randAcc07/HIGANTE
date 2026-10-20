/** A batch name as the automatic split sees it. */
export type SplitSlot = {
    name: string;
    /** Seats in its lab; null when unknown (no limit). */
    capacity: number | null;
    /** Learners already in it, who stay. */
    current: number;
};

/**
 * How many of the newcomers each batch takes: toward an even split of
 * everyone, never past a batch's seats while another batch has room. When
 * every batch is full, the rest go to the last one, which is reported as
 * over (capacity only ever warns).
 */
export function newcomerCounts(
    slots: SplitSlot[],
    newcomers: number,
): { counts: number[]; over: string[] } {
    const k = slots.length;

    if (k === 0) {
        return { counts: [], over: [] };
    }

    const room = (i: number, counts: number[]): number => {
        const capacity = slots[i].capacity;

        return capacity === null
            ? Infinity
            : Math.max(0, capacity - slots[i].current - counts[i]);
    };

    const total =
        slots.reduce((sum, slot) => sum + slot.current, 0) + newcomers;
    const counts = slots.map((slot, i) => {
        const target = Math.floor(total / k) + (i < total % k ? 1 : 0);

        return Math.max(0, target - slot.current);
    });

    // Batches already past their share leave less to hand out: trim from the end.
    let excess = counts.reduce((sum, count) => sum + count, 0) - newcomers;

    for (let i = k - 1; i >= 0 && excess > 0; i--) {
        const cut = Math.min(counts[i], excess);
        counts[i] -= cut;
        excess -= cut;
    }

    // Keep within seats, handing the overflow to batches that still have room.
    let spill = 0;

    for (let i = 0; i < k; i++) {
        const capacity = slots[i].capacity;

        if (capacity !== null && slots[i].current + counts[i] > capacity) {
            const fit = Math.max(0, capacity - slots[i].current);
            spill += counts[i] - fit;
            counts[i] = fit;
        }
    }

    for (let i = 0; i < k && spill > 0; i++) {
        const take = Math.min(room(i, counts), spill);
        counts[i] += take;
        spill -= take;
    }

    const over: string[] = [];

    if (spill > 0) {
        counts[k - 1] += spill;
        over.push(slots[k - 1].name);
    }

    return { counts, over };
}

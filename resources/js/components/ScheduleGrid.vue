<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { formatTime, fromMinutes, toMinutes, WEEKDAYS } from '@/lib/time';
import type { GridBlock, Weekday } from '@/types';

/**
 * A week of classes: Mon–Sat by default, 07:00–20:00 in 30-minute rows.
 * Clicking an empty half hour emits `slot`; clicking a class emits `block`.
 * Classes that overlap within a day (e.g. two batches' labs) sit side by
 * side.
 */
const props = withDefaults(
    defineProps<{
        blocks: GridBlock[];
        days?: Weekday[];
        from?: string;
        to?: string;
        step?: number;
        editable?: boolean;
    }>(),
    {
        days: () => [1, 2, 3, 4, 5, 6] as Weekday[],
        from: '07:00',
        to: '20:00',
        step: 30,
        editable: false,
    },
);

const emit = defineEmits<{
    slot: [weekday: Weekday, start: string];
    block: [block: GridBlock];
}>();

const ROW_HEIGHT = 28;
const start = computed(() => toMinutes(props.from));
const rows = computed(() => (toMinutes(props.to) - start.value) / props.step);
const height = computed(() => rows.value * ROW_HEIGHT);
const hours = computed(() =>
    Array.from({ length: rows.value / (60 / props.step) }, (_, i) =>
        fromMinutes(start.value + i * 60),
    ),
);

/** Each day's blocks with their lane, and how many lanes the day needs. */
const columns = computed(() =>
    props.days.map((day) => {
        const blocks = props.blocks
            .filter((block) => block.weekday === day)
            .sort((a, b) => toMinutes(a.start) - toMinutes(b.start));
        const laneEnds: number[] = [];
        const placed = blocks.map((block) => {
            let lane = laneEnds.findIndex(
                (end) => end <= toMinutes(block.start),
            );

            if (lane === -1) {
                lane = laneEnds.length;
                laneEnds.push(0);
            }

            laneEnds[lane] = toMinutes(block.end);

            return { block, lane };
        });

        return { day, placed, lanes: Math.max(1, laneEnds.length) };
    }),
);

const TONES = [
    'bg-sky-100 border-sky-300 text-sky-950 dark:bg-sky-950 dark:border-sky-800 dark:text-sky-100',
    'bg-emerald-100 border-emerald-300 text-emerald-950 dark:bg-emerald-950 dark:border-emerald-800 dark:text-emerald-100',
    'bg-amber-100 border-amber-300 text-amber-950 dark:bg-amber-950 dark:border-amber-800 dark:text-amber-100',
    'bg-violet-100 border-violet-300 text-violet-950 dark:bg-violet-950 dark:border-violet-800 dark:text-violet-100',
    'bg-rose-100 border-rose-300 text-rose-950 dark:bg-rose-950 dark:border-rose-800 dark:text-rose-100',
    'bg-teal-100 border-teal-300 text-teal-950 dark:bg-teal-950 dark:border-teal-800 dark:text-teal-100',
    'bg-orange-100 border-orange-300 text-orange-950 dark:bg-orange-950 dark:border-orange-800 dark:text-orange-100',
    'bg-indigo-100 border-indigo-300 text-indigo-950 dark:bg-indigo-950 dark:border-indigo-800 dark:text-indigo-100',
];

function tone(block: GridBlock): string {
    return block.tone === 'busy'
        ? 'border-dashed border-neutral-300 bg-[repeating-linear-gradient(45deg,transparent,transparent_6px,rgb(0_0_0/0.05)_6px,rgb(0_0_0/0.05)_12px)] text-muted-foreground dark:border-neutral-700'
        : TONES[block.tone % TONES.length];
}

function position(
    block: GridBlock,
    lane: number,
    lanes: number,
): Record<string, string> {
    return {
        top: `${((toMinutes(block.start) - start.value) / props.step) * ROW_HEIGHT}px`,
        height: `${((toMinutes(block.end) - toMinutes(block.start)) / props.step) * ROW_HEIGHT - 2}px`,
        left: `calc(${(lane / lanes) * 100}% + 2px)`,
        width: `calc(${100 / lanes}% - 4px)`,
    };
}

function pick(day: Weekday, event: MouseEvent): void {
    if (!props.editable || event.target !== event.currentTarget) {
        return;
    }

    const row = Math.floor(event.offsetY / ROW_HEIGHT);
    emit('slot', day, fromMinutes(start.value + row * props.step));
}
</script>

<template>
    <div class="overflow-x-auto rounded-lg border">
        <div
            class="grid min-w-[720px]"
            :style="{
                gridTemplateColumns: `4rem repeat(${days.length}, minmax(0, 1fr))`,
            }"
        >
            <div class="border-b bg-muted/50" />
            <div
                v-for="day in days"
                :key="`head-${day}`"
                class="border-b border-l bg-muted/50 px-2 py-1.5 text-center text-xs font-medium"
            >
                {{ WEEKDAYS[day] }}
            </div>

            <div class="relative" :style="{ height: `${height}px` }">
                <span
                    v-for="(hour, i) in hours"
                    :key="hour"
                    class="absolute right-2 -translate-y-1/2 text-[10px] text-muted-foreground"
                    :style="{ top: `${i * ROW_HEIGHT * (60 / step)}px` }"
                    >{{ i === 0 ? '' : formatTime(hour) }}</span
                >
            </div>

            <div
                v-for="column in columns"
                :key="`day-${column.day}`"
                class="relative border-l"
                :class="editable ? 'cursor-cell' : ''"
                :style="{
                    height: `${height}px`,
                    backgroundImage: `repeating-linear-gradient(to bottom, transparent 0, transparent ${ROW_HEIGHT * (60 / step) - 1}px, var(--border) ${ROW_HEIGHT * (60 / step) - 1}px, var(--border) ${ROW_HEIGHT * (60 / step)}px)`,
                }"
                :data-weekday="column.day"
                @click="pick(column.day, $event)"
            >
                <button
                    v-for="{ block, lane } in column.placed"
                    :key="block.key"
                    type="button"
                    class="absolute overflow-hidden rounded-md border px-1.5 py-1 text-left text-[11px] leading-tight shadow-xs"
                    :class="[
                        tone(block),
                        block.tone === 'busy'
                            ? 'cursor-default'
                            : 'hover:brightness-95',
                    ]"
                    :style="position(block, lane, column.lanes)"
                    :title="
                        [
                            block.title,
                            ...block.lines,
                            `${formatTime(block.start)}–${formatTime(block.end)}`,
                        ].join('\n')
                    "
                    @click="block.tone !== 'busy' && emit('block', block)"
                >
                    <span class="flex items-center gap-1 font-semibold">
                        <TriangleAlert
                            v-if="block.warning"
                            class="h-3 w-3 shrink-0"
                        />
                        {{ block.title }}
                    </span>
                    <span
                        v-for="line in block.lines"
                        :key="line"
                        class="block truncate opacity-80"
                        >{{ line }}</span
                    >
                </button>
            </div>
        </div>
    </div>
</template>

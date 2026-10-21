<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import GridZoomControl from '@/components/GridZoomControl.vue';
import { useGridZoom } from '@/composables/useGridZoom';
import type { GridZoom } from '@/composables/useGridZoom';
import { formatTime, fromMinutes, toMinutes, WEEKDAYS } from '@/lib/time';
import type { GridBlock, Weekday } from '@/types';

/**
 * A week of classes: Mon–Sat by default, 07:00–20:00. Clicking an empty
 * spot emits `slot` with the `step`-minute slot it falls in (classes are
 * scheduled in those, whatever the zoom); clicking a class emits `block`.
 * Classes that overlap within a day (e.g. two batches' labs) sit side by
 * side. With `dayHeads`, it is a dated week: dates and events in the
 * headers, and cancelled classes faded and struck through. Zoom with the − / + buttons, Ctrl+scroll or a pinch (useGridZoom).
 */
const props = withDefaults(
    defineProps<{
        blocks: GridBlock[];
        days?: Weekday[];
        from?: string;
        to?: string;
        step?: number;
        editable?: boolean;
        /** Shared zoom, when the page shows GridZoomControl in its own toolbar. */
        zoom?: GridZoom;
        /** A dated week: each day's date and its calendar events (a suspending one shades the day). */
        dayHeads?: Partial<
            Record<Weekday, { date: string; events: string[]; off: boolean }>
        >;
    }>(),
    {
        days: () => [1, 2, 3, 4, 5, 6] as Weekday[],
        from: '07:00',
        to: '20:00',
        step: 30,
        editable: false,
        zoom: undefined,
        dayHeads: undefined,
    },
);

const emit = defineEmits<{
    slot: [weekday: Weekday, start: string];
    block: [block: GridBlock];
}>();

// The page's zoom if it shows the control itself, else the grid's own.
const zoom = props.zoom ?? useGridZoom();
const ppm = zoom.pixelsPerMinute;
const start = computed(() => toMinutes(props.from));
const height = computed(() => (toMinutes(props.to) - start.value) * ppm.value);

/** The labelled lines at the current zoom level. */
const labels = computed(() =>
    Array.from(
        {
            length: Math.floor(
                (toMinutes(props.to) - start.value) / zoom.labelMinutes.value,
            ),
        },
        (_, i) => fromMinutes(start.value + i * zoom.labelMinutes.value),
    ),
);
const lineSpacing = computed(() => zoom.labelMinutes.value * ppm.value);

/**
 * Each day's blocks with their lane and how many lanes they share. Lanes are
 * worked out per overlap group, like a calendar app: a class that overlaps
 * nothing keeps the full width, and only classes that overlap split it, each
 * keeping one width for its whole height. Within a group, batches go in name
 * order, so e.g. 2-AA stays on the left.
 */
const columns = computed(() =>
    props.days.map((day) => {
        const blocks = props.blocks
            .filter((block) => block.weekday === day)
            .sort(
                (a, b) =>
                    toMinutes(a.start) - toMinutes(b.start) ||
                    (a.group ?? '').localeCompare(b.group ?? ''),
            );
        const placed: { block: GridBlock; lane: number; lanes: number }[] = [];
        let group: { block: GridBlock; lane: number }[] = [];
        let laneEnds: number[] = [];
        let groupEnd = -1;

        const closeGroup = (): void => {
            for (const item of group) {
                placed.push({ ...item, lanes: laneEnds.length });
            }

            group = [];
            laneEnds = [];
        };

        for (const block of blocks) {
            const blockStart = toMinutes(block.start);

            if (blockStart >= groupEnd) {
                closeGroup();
            }

            let lane = laneEnds.findIndex((laneEnd) => laneEnd <= blockStart);

            if (lane === -1) {
                lane = laneEnds.length;
                laneEnds.push(0);
            }

            laneEnds[lane] = toMinutes(block.end);
            groupEnd = Math.max(groupEnd, toMinutes(block.end));
            group.push({ block, lane });
        }

        closeGroup();

        return { day, placed };
    }),
);

// Listed in full so Tailwind can see each class; colors live in app.css.
const TONES = [
    'bg-event-1 border-event-1-border text-event-1-foreground',
    'bg-event-2 border-event-2-border text-event-2-foreground',
    'bg-event-3 border-event-3-border text-event-3-foreground',
    'bg-event-4 border-event-4-border text-event-4-foreground',
    'bg-event-5 border-event-5-border text-event-5-foreground',
    'bg-event-6 border-event-6-border text-event-6-foreground',
    'bg-event-7 border-event-7-border text-event-7-foreground',
    'bg-event-8 border-event-8-border text-event-8-foreground',
];

function tone(block: GridBlock): string {
    return block.tone === 'busy'
        ? 'border-dashed border-event-busy-border bg-[repeating-linear-gradient(45deg,transparent,transparent_6px,var(--event-busy-stripe)_6px,var(--event-busy-stripe)_12px)] text-muted-foreground'
        : TONES[block.tone % TONES.length];
}

function position(
    block: GridBlock,
    lane: number,
    lanes: number,
): Record<string, string> {
    return {
        top: `${(toMinutes(block.start) - start.value) * ppm.value}px`,
        height: `${(toMinutes(block.end) - toMinutes(block.start)) * ppm.value - 2}px`,
        left: `calc(${(lane / lanes) * 100}% + 2px)`,
        width: `calc(${100 / lanes}% - 4px)`,
    };
}

function pick(day: Weekday, event: MouseEvent): void {
    if (!props.editable || event.target !== event.currentTarget) {
        return;
    }

    const slot = Math.floor(event.offsetY / ppm.value / props.step);
    emit('slot', day, fromMinutes(start.value + slot * props.step));
}
</script>

<template>
    <div class="space-y-2">
        <div v-if="!props.zoom" class="flex justify-end">
            <GridZoomControl :zoom="zoom" />
        </div>

        <div
            class="max-h-[75vh] overflow-auto rounded-lg border"
            @wheel="zoom.onWheel"
            @touchstart="zoom.onTouchStart"
            @touchmove="zoom.onTouchMove"
            @touchend="zoom.onTouchEnd"
        >
            <div
                class="grid min-w-[720px]"
                :style="{
                    gridTemplateColumns: `4rem repeat(${days.length}, minmax(0, 1fr))`,
                }"
            >
                <div class="sticky top-0 z-10 border-b bg-muted" />
                <div
                    v-for="day in days"
                    :key="`head-${day}`"
                    class="sticky top-0 z-10 border-b border-l bg-muted px-2 py-1.5 text-center text-xs font-medium"
                >
                    {{ WEEKDAYS[day] }}
                    <template v-if="dayHeads?.[day]">
                        <span class="font-normal text-muted-foreground">
                            {{ dayHeads[day]?.date }}</span
                        >
                        <span
                            v-for="event in dayHeads[day]?.events"
                            :key="event"
                            class="block truncate font-normal"
                            :class="
                                dayHeads[day]?.off
                                    ? 'text-warning-soft-foreground'
                                    : 'text-muted-foreground'
                            "
                            :title="event"
                            >{{ event }}</span
                        >
                    </template>
                </div>

                <div class="relative" :style="{ height: `${height}px` }">
                    <span
                        v-for="(time, i) in labels"
                        :key="time"
                        class="absolute right-2 -translate-y-1/2 text-[10px] text-muted-foreground"
                        :style="{ top: `${i * lineSpacing}px` }"
                        >{{ i === 0 ? '' : formatTime(time) }}</span
                    >
                </div>

                <div
                    v-for="column in columns"
                    :key="`day-${column.day}`"
                    class="relative border-l"
                    :class="[
                        editable ? 'cursor-cell' : '',
                        dayHeads?.[column.day]?.off ? 'bg-warning-soft/40' : '',
                    ]"
                    :style="{
                        height: `${height}px`,
                        backgroundImage: `repeating-linear-gradient(to bottom, transparent 0, transparent ${lineSpacing - 1}px, var(--border) ${lineSpacing - 1}px, var(--border) ${lineSpacing}px)`,
                    }"
                    :data-weekday="column.day"
                    @click="pick(column.day, $event)"
                >
                    <button
                        v-for="{ block, lane, lanes } in column.placed"
                        :key="block.key"
                        type="button"
                        class="absolute overflow-hidden rounded-md border px-1.5 py-1 text-left text-[11px] leading-tight shadow-xs"
                        :class="[
                            tone(block),
                            block.tone === 'busy'
                                ? 'cursor-default'
                                : 'hover:brightness-95',
                            block.cancelled ? 'opacity-50' : '',
                        ]"
                        :style="position(block, lane, lanes)"
                        :title="
                            [
                                block.title,
                                ...block.lines,
                                `${formatTime(block.start)}–${formatTime(block.end)}`,
                            ].join('\n')
                        "
                        @click="block.tone !== 'busy' && emit('block', block)"
                    >
                        <span
                            class="flex items-center gap-1 font-semibold"
                            :class="block.cancelled ? 'line-through' : ''"
                        >
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
    </div>
</template>

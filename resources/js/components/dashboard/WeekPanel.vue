<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import AttendanceController from '@/actions/App/Http/Controllers/AttendanceController';
import ScheduleGrid from '@/components/ScheduleGrid.vue';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateRange } from '@/lib/dates';
import { dashboard } from '@/routes';
import type { DatedWeek, GridBlock, WeekClass, Weekday } from '@/types';

/**
 * A dated week of classes on the dashboard, stepped a week at a time.
 * Cancelled classes are struck through with the reason; a covered class
 * says who covers it. Teachers and deans open a held class's list.
 */
const props = defineProps<{
    week: DatedWeek;
    /** Whose week: decides each class's lines and whether it opens. */
    viewer: 'dean' | 'teacher' | 'learner';
    /** The dashboard prop to reload when stepping (the role's panel). */
    panel: string;
    /** Other query values to keep when stepping (the dean's section). */
    query?: Record<string, string | number | null>;
    today: string;
}>();

// In the header's toolbar rather than above the grid.

const byKey = computed(
    () => new Map(props.week.blocks.map((item) => [key(item), item])),
);

function key(item: WeekClass): string {
    return `${item.id}-${item.date}`;
}

function lines(item: WeekClass): string[] {
    const section = `${item.program} ${item.section}`;
    const lines =
        props.viewer === 'dean'
            ? [item.teacher, item.room ?? 'Online']
            : props.viewer === 'teacher'
              ? [section, item.room ?? 'Online']
              : [item.room ?? 'Online', item.teacher];

    if (item.cancelled) {
        return [item.cancelled, ...lines];
    }

    if (item.covering) {
        return [`Covering for ${item.teacher}`, section, item.room ?? 'Online'];
    }

    return item.cover ? [`Covered by ${item.cover}`, ...lines] : lines;
}

const blocks = computed<GridBlock[]>(() => {
    const tones = new Map<string, number>();

    return props.week.blocks.map((item) => {
        const group = `${item.subject} ${item.section}`;

        if (!tones.has(group)) {
            tones.set(group, tones.size);
        }

        return {
            key: key(item),
            id: item.id,
            weekday: item.weekday,
            start: item.start,
            end: item.end,
            title: item.batch
                ? `${item.subject} · ${item.batch}`
                : item.subject,
            lines: lines(item),
            tone: tones.get(group) ?? 0,
            group: item.batch ?? '',
            cancelled: item.cancelled !== null,
        };
    });
});

const heads = computed(
    () =>
        Object.fromEntries(
            props.week.days.map((day) => [
                day.weekday,
                {
                    date: formatDate(day.date, {
                        month: 'short',
                        day: 'numeric',
                    }),
                    events: day.events.map((event) => event.title),
                    off: day.events.some((event) => event.suspends),
                },
            ]),
        ) as Record<Weekday, { date: string; events: string[]; off: boolean }>,
);

const range = computed(() =>
    formatDateRange(
        props.week.days[0]?.date ?? props.week.monday,
        props.week.days.at(-1)?.date ?? props.week.monday,
    ),
);
const isThisWeek = computed(
    () =>
        props.week.days[0]!.date <= props.today &&
        props.today <= props.week.days.at(-1)!.date,
);

function go(week: string | null): void {
    router.get(
        dashboard.url(),
        { ...props.query, week },
        { preserveScroll: true, preserveState: true, only: [props.panel] },
    );
}

function open(block: GridBlock): void {
    const item = byKey.value.get(block.key);

    if (
        props.viewer === 'learner' ||
        !item ||
        item.cancelled ||
        item.date > props.today
    ) {
        return;
    }

    router.visit(
        AttendanceController.show.url({ schedule: item.id, date: item.date }),
    );
}
</script>

<template>
    <div class="space-y-3">
        <ScheduleGrid :blocks="blocks" :day-heads="heads" @block="open">
            <template #heading>
                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex items-center gap-1">
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-9"
                            :disabled="!week.previous"
                            aria-label="Previous week"
                            @click="go(week.previous)"
                            ><ChevronLeft class="h-4 w-4"
                        /></Button>
                        <Button
                            v-if="!isThisWeek"
                            variant="outline"
                            size="sm"
                            class="h-9"
                            @click="go(null)"
                            >This week</Button
                        >
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-9"
                            :disabled="!week.next"
                            aria-label="Next week"
                            @click="go(week.next)"
                            ><ChevronRight class="h-4 w-4"
                        /></Button>
                    </div>
                    <h2 class="font-medium">
                        {{ range }}
                        <span
                            v-if="isThisWeek"
                            class="text-sm font-normal text-muted-foreground"
                            >· current week</span
                        >
                    </h2>
                </div>
            </template>
        </ScheduleGrid>
        <p
            v-if="week.blocks.length === 0"
            class="text-center text-sm text-muted-foreground"
        >
            No classes this week.
        </p>
    </div>
</template>

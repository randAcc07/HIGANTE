<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import CalendarController from '@/actions/App/Http/Controllers/CalendarController';
import { Button } from '@/components/ui/button';
import { parseDate } from '@/lib/dates';
import { dashboard } from '@/routes';
import type { MonthEvents } from '@/types';

/**
 * A month of campus events (Mon–Sun weeks). Each event opens the Calendar
 * page at its entry; a term's start and end are marked on their days.
 */
const props = defineProps<{
    month: MonthEvents;
    today: string;
}>();

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function iso(date: Date): string {
    return date.toLocaleDateString('en-CA');
}

/** The month's weeks, each Mon–Sun, padded with the neighbouring months' days. */
const weeks = computed(() => {
    const first = parseDate(props.month.first);
    const start = new Date(first);
    start.setDate(first.getDate() - ((first.getDay() + 6) % 7));
    const weeks: { date: string; day: number; inMonth: boolean }[][] = [];

    for (const day = new Date(start); ;) {
        const week = [];

        for (let i = 0; i < 7; i++) {
            week.push({
                date: iso(day),
                day: day.getDate(),
                inMonth: day.getMonth() === first.getMonth(),
            });
            day.setDate(day.getDate() + 1);
        }

        weeks.push(week);

        if (day.getMonth() !== first.getMonth()) {
            break;
        }
    }

    return weeks;
});

const title = computed(() =>
    parseDate(props.month.first).toLocaleDateString(undefined, {
        month: 'long',
        year: 'numeric',
    }),
);
const isThisMonth = computed(
    () => props.today.slice(0, 7) === props.month.first.slice(0, 7),
);

function eventsOn(date: string): MonthEvents['events'] {
    return props.month.events.filter(
        (event) => event.starts_on <= date && date <= event.ends_on,
    );
}

function marksOn(date: string): string[] {
    return props.month.marks
        .filter((mark) => mark.date === date)
        .map((mark) => mark.label);
}

function go(month: string | null): void {
    router.get(
        dashboard.url(),
        { month },
        { preserveScroll: true, preserveState: true, only: ['admin'] },
    );
}

function open(event: MonthEvents['events'][number]): void {
    router.visit(
        CalendarController.index.url({
            query: { term: event.term_id, event: event.id },
        }),
    );
}
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center gap-3">
            <div class="flex items-center gap-1">
                <Button
                    variant="outline"
                    size="sm"
                    class="h-9"
                    aria-label="Previous month"
                    @click="go(month.previous)"
                    ><ChevronLeft class="h-4 w-4"
                /></Button>
                <Button
                    v-if="!isThisMonth"
                    variant="outline"
                    size="sm"
                    class="h-9"
                    @click="go(null)"
                    >This month</Button
                >
                <Button
                    variant="outline"
                    size="sm"
                    class="h-9"
                    aria-label="Next month"
                    @click="go(month.next)"
                    ><ChevronRight class="h-4 w-4"
                /></Button>
            </div>
            <h2 class="font-medium">{{ title }}</h2>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <div class="grid min-w-[640px] grid-cols-7">
                <div
                    v-for="weekday in WEEKDAYS"
                    :key="weekday"
                    class="border-b bg-muted px-2 py-1.5 text-center text-xs font-medium"
                >
                    {{ weekday }}
                </div>
                <template v-for="week in weeks" :key="week[0]!.date">
                    <div
                        v-for="(cell, i) in week"
                        :key="cell.date"
                        class="min-h-24 space-y-1 border-b p-1.5"
                        :class="[
                            i > 0 ? 'border-l' : '',
                            cell.inMonth
                                ? ''
                                : 'bg-muted/40 text-muted-foreground',
                        ]"
                        :data-date="cell.date"
                    >
                        <div class="flex items-center justify-between">
                            <span
                                class="inline-flex h-6 w-6 items-center justify-center rounded-full text-xs"
                                :class="
                                    cell.date === today
                                        ? 'bg-primary font-semibold text-primary-foreground'
                                        : ''
                                "
                                >{{ cell.day }}</span
                            >
                        </div>
                        <p
                            v-for="mark in marksOn(cell.date)"
                            :key="mark"
                            class="truncate text-[11px] font-medium text-highlight"
                            :title="mark"
                        >
                            {{ mark }}
                        </p>
                        <button
                            v-for="event in eventsOn(cell.date)"
                            :key="event.id"
                            type="button"
                            class="block w-full cursor-pointer truncate rounded px-1.5 py-0.5 text-left text-[11px] hover:brightness-95"
                            :class="
                                event.suspends
                                    ? 'bg-warning-soft text-warning-soft-foreground'
                                    : 'bg-info-soft text-info-soft-foreground'
                            "
                            :title="`${event.title} · ${event.college ?? 'Campus-wide'}${event.suspends ? ' · no classes' : ''}`"
                            @click="open(event)"
                        >
                            <span v-if="event.college" class="font-medium"
                                >{{ event.college }} ·
                            </span>
                            {{ event.title }}
                        </button>
                    </div>
                </template>
            </div>
        </div>
        <p class="text-xs text-muted-foreground">
            <span
                class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-warning-soft align-middle"
            />
            Classes suspended
            <span
                class="mr-1 ml-3 inline-block h-2.5 w-2.5 rounded-sm bg-info-soft align-middle"
            />
            Other events · open one to see it on the Calendar
        </p>
    </div>
</template>

<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import ClassController from '@/actions/App/Http/Controllers/Teacher/ClassController';
import DayClasses from '@/components/attendance/DayClasses.vue';
import DayPicker from '@/components/attendance/DayPicker.vue';
import Heading from '@/components/Heading.vue';
import ScheduleGrid from '@/components/ScheduleGrid.vue';
import { NativeSelect } from '@/components/ui/native-select';
import { useFilters } from '@/composables/useFilters';
import { formatDate } from '@/lib/dates';
import type { ClassBlock, DayClass, GridBlock, TermPhase } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'My classes', href: ClassController.index() }],
    },
});

type TermOption = { id: number; label: string; phase: TermPhase };

const props = defineProps<{
    terms: TermOption[];
    term: (TermOption & { starts_on: string; ends_on: string }) | null;
    date: string;
    today: string;
    classes: DayClass[];
    week: ClassBlock[];
}>();

const filters = useFilters(ClassController.index.url(), {
    term: props.term?.id ?? null,
    date: props.date as string | null,
});

const day = computed({
    get: () => filters.date ?? props.today,
    set: (value: string) => (filters.date = value),
});

const phaseLabels: Record<TermPhase, string> = {
    upcoming: 'Upcoming',
    in_session: 'In session',
    ended: 'Ended',
    closed: 'Closed',
};

const blocks = computed<GridBlock[]>(() => {
    const tones = new Map<string, number>();

    return props.week.map((item) => {
        const group = `${item.subject} ${item.section}`;

        if (!tones.has(group)) {
            tones.set(group, tones.size);
        }

        return {
            key: `class-${item.id}`,
            id: item.id,
            weekday: item.weekday,
            start: item.start,
            end: item.end,
            title: item.batch
                ? `${item.subject} · ${item.batch}`
                : item.subject,
            lines: [item.section, item.room ?? 'Online'],
            tone: tones.get(group) ?? 0,
            group: item.batch ?? '',
        };
    });
});
</script>

<template>
    <Head title="My classes" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="My classes"
                :description="
                    term
                        ? `${term.label} · ${phaseLabels[term.phase]}`
                        : 'No terms yet'
                "
            />
            <NativeSelect
                v-if="terms.length > 1"
                v-model="filters.term"
                aria-label="Term"
            >
                <option
                    v-for="option in terms"
                    :key="option.id"
                    :value="option.id"
                >
                    {{ option.label }} ({{ phaseLabels[option.phase] }})
                </option>
            </NativeSelect>
        </div>

        <section v-if="term" class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <Heading
                    variant="small"
                    :title="
                        date === today
                            ? 'Today'
                            : formatDate(date, {
                                  weekday: 'long',
                                  month: 'long',
                                  day: 'numeric',
                              })
                    "
                    description="Open a class to take or correct its attendance"
                />
                <DayPicker v-model="day" :today="today" />
            </div>
            <DayClasses
                :classes="classes"
                :date="date"
                empty="You have no classes on this day."
            />
        </section>

        <section v-if="term" class="space-y-3">
            <ScheduleGrid :blocks="blocks">
                <template #heading>
                    <Heading
                        variant="small"
                        title="Week"
                        description="Your classes this term"
                    />
                </template>
            </ScheduleGrid>
        </section>
    </div>
</template>

<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AttendanceController from '@/actions/App/Http/Controllers/Dean/AttendanceController';
import DayClasses from '@/components/attendance/DayClasses.vue';
import DayPicker from '@/components/attendance/DayPicker.vue';
import Heading from '@/components/Heading.vue';
import { useFilters } from '@/composables/useFilters';
import { formatDate } from '@/lib/dates';
import type { DayClass, Program, TeacherOption, TermPhase } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Attendance', href: AttendanceController.index() },
        ],
    },
});

type TermOption = { id: number; label: string; phase: TermPhase };

const props = defineProps<{
    terms: TermOption[];
    term: (TermOption & { starts_on: string; ends_on: string }) | null;
    date: string;
    today: string;
    sections: {
        id: number;
        name: string;
        program: Pick<Program, 'id' | 'code'>;
    }[];
    teachers: TeacherOption[];
    classes: DayClass[];
    filters: { section: number | null; teacher: number | null };
}>();

const filters = useFilters(AttendanceController.index.url(), {
    term: props.term?.id ?? null,
    date: props.date as string | null,
    section: props.filters.section,
    teacher: props.filters.teacher,
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

const marked = computed(() =>
    props.classes.reduce(
        (sum, item) => ({
            late: sum.late + item.late,
            absent: sum.absent + item.absent,
        }),
        { late: 0, absent: 0 },
    ),
);
</script>

<template>
    <Head title="Attendance" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Attendance"
                :description="
                    term
                        ? `${term.label} · ${phaseLabels[term.phase]} · teachers mark their classes; open one to see its marks`
                        : 'No terms yet'
                "
            />
            <select
                v-if="terms.length > 1"
                v-model="filters.term"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                aria-label="Term"
            >
                <option
                    v-for="option in terms"
                    :key="option.id"
                    :value="option.id"
                >
                    {{ option.label }} ({{ phaseLabels[option.phase] }})
                </option>
            </select>
        </div>

        <div v-if="term" class="flex flex-wrap items-center gap-3">
            <DayPicker v-model="day" :today="today" />
            <select
                v-model="filters.section"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                aria-label="Section"
            >
                <option :value="null">All sections</option>
                <option
                    v-for="section in sections"
                    :key="section.id"
                    :value="section.id"
                >
                    {{ section.program.code }} {{ section.name }}
                </option>
            </select>
            <select
                v-model="filters.teacher"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                aria-label="Teacher"
            >
                <option :value="null">All teachers</option>
                <option
                    v-for="teacher in teachers"
                    :key="teacher.id"
                    :value="teacher.id"
                >
                    {{ teacher.name }}
                </option>
            </select>
        </div>

        <section v-if="term" class="space-y-3">
            <Heading
                variant="small"
                :title="
                    formatDate(date, {
                        weekday: 'long',
                        month: 'long',
                        day: 'numeric',
                    })
                "
                :description="
                    classes.length
                        ? `${classes.length} class${classes.length === 1 ? '' : 'es'}${marked.late ? ` · ${marked.late} late` : ''}${marked.absent ? ` · ${marked.absent} absent` : ''}`
                        : ''
                "
            />
            <DayClasses
                :classes="classes"
                :date="date"
                show-teacher
                empty="No classes match on this day."
            />
        </section>
    </div>
</template>

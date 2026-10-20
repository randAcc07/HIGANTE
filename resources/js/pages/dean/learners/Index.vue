<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import LearnerController from '@/actions/App/Http/Controllers/Dean/LearnerController';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/composables/useFilters';
import type { Paginated, Program, TermPhase } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Learners', href: LearnerController.index() }],
    },
});

type TermOption = { id: number; label: string; phase: TermPhase };

type Row = {
    id: number;
    year_level: number;
    is_irregular: boolean;
    status: 'registered' | 'withdrawn';
    subjects_count: number;
    section: { id: number; name: string } | null;
    learner: {
        id: number;
        student_number: string;
        user: { first_name: string; last_name: string };
        program: Pick<Program, 'id' | 'code'>;
    };
};

const props = defineProps<{
    terms: TermOption[];
    term: TermOption | null;
    programs: Pick<Program, 'id' | 'code' | 'name'>[];
    sections: {
        id: number;
        program_id: number;
        year_level: number;
        name: string;
        program: Pick<Program, 'id' | 'code'>;
    }[];
    registrations: Paginated<Row> | null;
    unsectioned: number;
    filters: {
        search: string | null;
        program: number | null;
        year: number | null;
        section: string | null;
        irregular: boolean | null;
        status: string | null;
    };
}>();

const filters = useFilters(LearnerController.index.url(), {
    term: props.term?.id ?? null,
    search: props.filters.search ?? '',
    program: props.filters.program,
    year: props.filters.year,
    section: props.filters.section,
    irregular: (props.filters.irregular === null
        ? null
        : Number(props.filters.irregular)) as number | null,
    status: props.filters.status,
});

const phaseLabels: Record<TermPhase, string> = {
    upcoming: 'Upcoming',
    in_session: 'In session',
    ended: 'Ended',
    closed: 'Closed',
};

function showQueue(): void {
    filters.section = 'none';
    filters.status = null;
}
</script>

<template>
    <Head title="Learners" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Learners"
                :description="
                    term
                        ? `${term.label} · ${phaseLabels[term.phase]} · sections, subjects and batches`
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

        <div
            v-if="unsectioned > 0 && filters.section !== 'none'"
            class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-warning/50 bg-warning-soft p-3 text-sm text-warning-soft-foreground"
        >
            <span
                >{{ unsectioned }} registered learner{{
                    unsectioned === 1 ? ' has' : 's have'
                }}
                no section: every section of their program and year level was
                full.</span
            >
            <Button size="sm" variant="outline" @click="showQueue"
                >Show them</Button
            >
        </div>

        <div class="flex flex-wrap gap-3">
            <Input
                v-model="filters.search"
                class="max-w-xs"
                placeholder="Search name, email or student number"
                aria-label="Search learners"
            />
            <select
                v-model="filters.program"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                aria-label="Program"
                @change="filters.section = null"
            >
                <option :value="null">All programs</option>
                <option
                    v-for="program in programs"
                    :key="program.id"
                    :value="program.id"
                >
                    {{ program.code }}
                </option>
            </select>
            <select
                v-model="filters.year"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                aria-label="Year level"
            >
                <option :value="null">All years</option>
                <option v-for="year in 6" :key="year" :value="year">
                    Year {{ year }}
                </option>
            </select>
            <select
                v-model="filters.section"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                aria-label="Section"
            >
                <option :value="null">All sections</option>
                <option value="none">No section</option>
                <option
                    v-for="section in sections.filter(
                        (section) =>
                            (filters.program === null ||
                                section.program_id === filters.program) &&
                            (filters.year === null ||
                                section.year_level === filters.year),
                    )"
                    :key="section.id"
                    :value="String(section.id)"
                >
                    {{ section.program.code }} {{ section.name }}
                </option>
            </select>
            <select
                v-model="filters.irregular"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                aria-label="Regular or irregular"
            >
                <option :value="null">Regular and irregular</option>
                <option :value="0">Regular</option>
                <option :value="1">Irregular</option>
            </select>
            <select
                v-model="filters.status"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                aria-label="Status"
            >
                <option :value="null">Registered</option>
                <option value="withdrawn">Withdrawn</option>
            </select>
        </div>

        <template v-if="registrations">
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Name</th>
                            <th class="px-4 py-2 font-medium">Program</th>
                            <th class="px-4 py-2 font-medium">Year</th>
                            <th class="px-4 py-2 font-medium">Section</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Subjects
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in registrations.data"
                            :key="row.id"
                            class="border-t"
                        >
                            <td class="px-4 py-2">
                                <Link
                                    :href="LearnerController.show(row.id)"
                                    class="font-medium hover:underline"
                                >
                                    {{ row.learner.user.last_name }},
                                    {{ row.learner.user.first_name }}
                                </Link>
                                <Badge
                                    v-if="row.is_irregular"
                                    variant="outline"
                                    class="ml-2"
                                    >Irregular</Badge
                                >
                                <div
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ row.learner.student_number }}
                                </div>
                            </td>
                            <td class="px-4 py-2">
                                {{ row.learner.program.code }}
                            </td>
                            <td class="px-4 py-2">{{ row.year_level }}</td>
                            <td class="px-4 py-2">
                                <template v-if="row.section">{{
                                    row.section.name
                                }}</template>
                                <Badge
                                    v-else-if="row.status === 'registered'"
                                    variant="secondary"
                                    >No section</Badge
                                >
                                <template v-else>—</template>
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ row.subjects_count }}
                            </td>
                        </tr>
                        <tr v-if="registrations.data.length === 0">
                            <td
                                colspan="5"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                No learners match.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="registrations" />
        </template>
    </div>
</template>

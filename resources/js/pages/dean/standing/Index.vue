<script setup lang="ts">
import { Pencil } from '@lucide/vue';
import { Head, Link } from '@inertiajs/vue3';
import LearnerController from '@/actions/App/Http/Controllers/Dean/LearnerController';
import StandingController from '@/actions/App/Http/Controllers/Dean/StandingController';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useFilters } from '@/composables/useFilters';
import { formatDate } from '@/lib/dates';
import type { Paginated, Program, TermPhase } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'At risk', href: StandingController.index() }],
    },
});

type TermOption = { id: number; label: string; phase: TermPhase };

type Row = {
    id: number;
    registration_id: number | null;
    name: string;
    full_name: string;
    student_number: string;
    subject: { code: string; title: string };
    section: string;
    status: 'enrolled' | 'for_dropping' | 'dropped';
    warning_level: number;
    counts: {
        absences: number;
        lates: number;
        excused: number;
        equivalent: number;
    };
    periods: { held: number; total: number };
    flagged_on: string | null;
    dropped_on: string | null;
    dismissed_at_absences: number | null;
    reinstated_on: string | null;
};

const props = defineProps<{
    terms: TermOption[];
    term: TermOption | null;
    policy: {
        lates_per_absence: number;
        drop_at_absences: number;
        warn_at_absences: number[];
    } | null;
    counts: { flagged: number; warned: number; dropped: number } | null;
    sections: {
        id: number;
        name: string;
        program: Pick<Program, 'id' | 'code'>;
    }[];
    enrollments: Paginated<Row>;
    canDecide: boolean;
    filters: {
        status: 'flagged' | 'warned' | 'dropped' | null;
        section: number | null;
        search: string | null;
    };
}>();

const filters = useFilters(StandingController.index.url(), {
    term: props.term?.id ?? null,
    status: props.filters.status as string | null,
    section: props.filters.section,
    search: props.filters.search ?? '',
});

const phaseLabels: Record<TermPhase, string> = {
    upcoming: 'Upcoming',
    in_session: 'In session',
    ended: 'Ended',
    closed: 'Closed',
};

const tabs = [
    { value: null, label: 'All' },
    { value: 'flagged', label: 'For dropping' },
    { value: 'warned', label: 'Warned' },
    { value: 'dropped', label: 'Dropped' },
] as const;

function tabCount(value: (typeof tabs)[number]['value']): string {
    if (!props.counts || value === null) {
        return '';
    }

    return ` (${props.counts[value]})`;
}

function breakdown(row: Row): string {
    const parts = [`${row.counts.absences} absent`, `${row.counts.lates} late`];

    if (row.counts.excused) {
        parts.push(`${row.counts.excused} excused`);
    }

    return parts.join(' · ');
}
</script>

<template>
    <Head title="At risk" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="At risk"
                :description="
                    term && policy
                        ? `${term.label} · ${phaseLabels[term.phase]} · warned at ${policy.warn_at_absences.join(' and ')}, flagged at ${policy.drop_at_absences} equivalent absences (${policy.lates_per_absence} lates = 1 absence)`
                        : term
                          ? term.label
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

        <div class="flex flex-wrap items-center gap-2 border-b">
            <button
                v-for="tab in tabs"
                :key="tab.label"
                type="button"
                class="-mb-px border-b-2 px-3 py-2 text-sm font-medium"
                :class="
                    filters.status === tab.value
                        ? 'border-primary'
                        : 'border-transparent text-muted-foreground'
                "
                @click="filters.status = tab.value"
            >
                {{ tab.label }}{{ tabCount(tab.value) }}
            </button>
        </div>

        <div class="flex flex-wrap gap-3">
            <Input
                v-model="filters.search"
                class="max-w-xs"
                placeholder="Search name, email or student number"
                aria-label="Search learners"
            />
            <NativeSelect v-model="filters.section" aria-label="Section">
                <option :value="null">All sections</option>
                <option
                    v-for="section in sections"
                    :key="section.id"
                    :value="section.id"
                >
                    {{ section.program.code }} {{ section.name }}
                </option>
            </NativeSelect>
        </div>

        <div v-table-scroll class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Learner</th>
                        <th class="px-4 py-2 font-medium">Class</th>
                        <th class="px-4 py-2 font-medium">
                            Equivalent absences
                        </th>
                        <th class="px-4 py-2 font-medium">Standing</th>
                        <th class="table-actions px-4 py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in enrollments.data"
                        :key="row.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2">
                            <span class="font-medium">{{ row.name }}</span>
                            <div
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ row.student_number }}
                            </div>
                        </td>
                        <td class="px-4 py-2">
                            <span class="font-medium">{{
                                row.subject.code
                            }}</span>
                            <span class="text-muted-foreground">
                                · {{ row.section }}</span
                            >
                            <div class="text-xs text-muted-foreground">
                                {{ row.subject.title }}
                            </div>
                        </td>
                        <td class="px-4 py-2">
                            <span class="font-medium">{{
                                row.counts.equivalent
                            }}</span>
                            <span v-if="policy" class="text-muted-foreground">
                                of {{ policy.drop_at_absences }}</span
                            >
                            <div class="text-xs text-muted-foreground">
                                {{ breakdown(row) }} · {{ row.periods.held }} of
                                {{ row.periods.total }} periods held
                            </div>
                        </td>
                        <td class="px-4 py-2">
                            <template v-if="row.status === 'for_dropping'">
                                <Badge variant="destructive"
                                    >For dropping</Badge
                                >
                                <div
                                    v-if="row.flagged_on"
                                    class="text-xs text-muted-foreground"
                                >
                                    since {{ formatDate(row.flagged_on) }}
                                </div>
                            </template>
                            <template v-else-if="row.status === 'dropped'">
                                <Badge variant="secondary">Dropped</Badge>
                                <div
                                    v-if="row.dropped_on"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ formatDate(row.dropped_on) }}
                                </div>
                            </template>
                            <template v-else>
                                <Badge variant="outline"
                                    >Warned at {{ row.warning_level }}</Badge
                                >
                                <div
                                    v-if="row.dismissed_at_absences !== null"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{
                                        row.reinstated_on
                                            ? `Reinstated ${formatDate(row.reinstated_on)}`
                                            : 'Flag dismissed'
                                    }}
                                    at {{ row.dismissed_at_absences }}
                                </div>
                            </template>
                        </td>
                        <td class="table-actions px-4 py-2">
                            <div class="flex justify-end gap-1">
                                <Button
                                    v-if="row.registration_id"
                                    variant="ghost"
                                    size="sm"
                                    as-child
                                    title="Edit learner"
                                >
                                    <Link
                                        :href="
                                            LearnerController.show(
                                                row.registration_id,
                                            )
                                        "
                                    >
                                        <Pencil class="h-4 w-4" />
                                        <span class="sr-only"
                                            >Edit learner</span
                                        >
                                    </Link>
                                </Button>
                                <template v-if="canDecide">
                                    <template
                                        v-if="row.status === 'for_dropping'"
                                    >
                                        <ConfirmActionDialog
                                            :title="`Drop ${row.full_name} from ${row.subject.code}?`"
                                            :description="`They have ${row.counts.equivalent} equivalent absences. They leave the class list from today and are told. You can reinstate them later if needed.`"
                                            confirm-label="Drop"
                                            destructive
                                            :url="
                                                StandingController.drop.url(
                                                    row.id,
                                                )
                                            "
                                        >
                                            <Button variant="ghost" size="sm"
                                                >Drop</Button
                                            >
                                        </ConfirmActionDialog>
                                        <ConfirmActionDialog
                                            :title="`Keep ${row.full_name} in ${row.subject.code}?`"
                                            description="The flag is lifted. One more equivalent absence flags them again."
                                            confirm-label="Keep in class"
                                            :url="
                                                StandingController.dismiss.url(
                                                    row.id,
                                                )
                                            "
                                        >
                                            <Button variant="ghost" size="sm"
                                                >Dismiss</Button
                                            >
                                        </ConfirmActionDialog>
                                    </template>
                                    <ConfirmActionDialog
                                        v-else-if="row.status === 'dropped'"
                                        :title="`Reinstate ${row.full_name} in ${row.subject.code}?`"
                                        description="They return to the class list and are told. Like a dismissed flag, one more equivalent absence flags them again."
                                        confirm-label="Reinstate"
                                        :url="
                                            StandingController.reinstate.url(
                                                row.id,
                                            )
                                        "
                                    >
                                        <Button variant="ghost" size="sm"
                                            >Reinstate</Button
                                        >
                                    </ConfirmActionDialog>
                                </template>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="enrollments.data.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            Nobody here: no warnings or drops match.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="enrollments" />
    </div>
</template>

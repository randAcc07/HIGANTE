<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import ExcuseDecisionController from '@/actions/App/Http/Controllers/ExcuseDecisionController';
import ExcuseController from '@/actions/App/Http/Controllers/Teacher/ExcuseController';
import Heading from '@/components/Heading.vue';
import ExcuseFormDialog from '@/components/learner/ExcuseFormDialog.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/composables/useFilters';
import { formatDate, formatDateRange } from '@/lib/dates';
import { decisionLabels, lateLabel, reasonLabels } from '@/lib/excuses';
import type {
    DecisionStatus,
    ExcuseDecision,
    ExcuseLearner,
    ExcuseReason,
    Paginated,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Excuses', href: ExcuseController.index() }],
    },
});

type Row = ExcuseDecision & {
    excuse: {
        date_from: string;
        date_to: string;
        reason: ExcuseReason;
        documents: number;
        days_late: number;
        submitted_on: string | null;
    };
    learner: ExcuseLearner;
};

const props = defineProps<{
    decisions: Paginated<Row>;
    counts: Record<DecisionStatus, number>;
    filters: { status: DecisionStatus; search: string | null };
    learners: { id: number; name: string; student_number: string }[];
    reasons: ExcuseReason[];
    maxDays: number;
    dueWithinDays: number;
}>();

const filters = useFilters(ExcuseController.index.url(), {
    status: props.filters.status as string | null,
    search: props.filters.search ?? '',
});

const tabs: DecisionStatus[] = ['pending', 'approved', 'declined', 'revoked'];
const tabLabels: Record<DecisionStatus, string> = {
    ...decisionLabels,
    pending: 'To decide',
};
</script>

<template>
    <Head title="Excuses" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Excuses"
                description="Excuses for your classes: decide each for your own class"
            />
            <ExcuseFormDialog
                v-if="learners.length"
                :reasons="reasons"
                :max-days="maxDays"
                :due-within-days="dueWithinDays"
                :learners="learners"
                :url="ExcuseController.store.url()"
            >
                <Button
                    ><Plus class="h-4 w-4" /> File an excuse for a
                    learner</Button
                >
            </ExcuseFormDialog>
        </div>

        <div class="flex flex-wrap items-center gap-2 border-b">
            <button
                v-for="tab in tabs"
                :key="tab"
                type="button"
                class="-mb-px border-b-2 px-3 py-2 text-sm font-medium"
                :class="
                    filters.status === tab
                        ? 'border-primary'
                        : 'border-transparent text-muted-foreground'
                "
                @click="filters.status = tab"
            >
                {{ tabLabels[tab] }} ({{ counts[tab] }})
            </button>
        </div>

        <Input
            v-model="filters.search"
            class="max-w-xs"
            placeholder="Search name, email or student number"
            aria-label="Search learners"
        />

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Learner</th>
                        <th class="px-4 py-2 font-medium">Class</th>
                        <th class="px-4 py-2 font-medium">Days</th>
                        <th class="px-4 py-2 font-medium">Reason</th>
                        <th class="px-4 py-2 font-medium">Submitted</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in decisions.data"
                        :key="row.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2">
                            <Link
                                :href="ExcuseDecisionController.show(row.id)"
                                class="font-medium text-highlight hover:underline"
                                >{{ row.learner.name }}</Link
                            >
                            <div class="text-xs text-muted-foreground">
                                {{ row.learner.student_number }} ·
                                {{ row.learner.program }}
                            </div>
                        </td>
                        <td class="px-4 py-2">
                            {{ row.subject.code }}
                            <span class="text-muted-foreground"
                                >· {{ row.section }}</span
                            >
                        </td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{
                                formatDateRange(
                                    row.excuse.date_from,
                                    row.excuse.date_to,
                                )
                            }}
                        </td>
                        <td class="px-4 py-2">
                            {{ reasonLabels[row.excuse.reason] }}
                            <span
                                v-if="row.excuse.documents"
                                class="text-muted-foreground"
                                >· {{ row.excuse.documents }} file{{
                                    row.excuse.documents === 1 ? '' : 's'
                                }}</span
                            >
                        </td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{
                                row.excuse.submitted_on
                                    ? formatDate(row.excuse.submitted_on)
                                    : ''
                            }}
                            <Badge
                                v-if="row.excuse.days_late"
                                variant="secondary"
                                class="ml-2"
                                >{{ lateLabel(row.excuse.days_late) }}</Badge
                            >
                        </td>
                    </tr>
                    <tr v-if="decisions.data.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            {{
                                filters.status === 'pending'
                                    ? 'Nothing to decide right now.'
                                    : 'None here.'
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="decisions" />
    </div>
</template>

<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ExcuseController from '@/actions/App/Http/Controllers/Dean/ExcuseController';
import Heading from '@/components/Heading.vue';
import TermPicker from '@/components/TermPicker.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/composables/useFilters';
import { formatDate, formatDateRange } from '@/lib/dates';
import { lateLabel, reasonLabels, statusLabels } from '@/lib/excuses';
import type { Excuse, ExcuseLearner, ExcuseStatus, Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Excuses', href: ExcuseController.index() }],
    },
});

const props = defineProps<{
    excuses: Paginated<
        Excuse & { learner: ExcuseLearner; dean_decides: number }
    >;
    counts: Record<ExcuseStatus, number>;
    filters: {
        term: number | null;
        status: ExcuseStatus;
        search: string | null;
    };
    terms: { id: number; label: string }[];
    term: { id: number; label: string } | null;
}>();

const filters = useFilters(ExcuseController.index.url(), {
    term: props.filters.term,
    status: props.filters.status as string | null,
    search: props.filters.search ?? '',
});

const tabs: ExcuseStatus[] = [
    'pending',
    'partial',
    'approved',
    'rejected',
    'revoked',
];

function decided(excuse: Excuse): string {
    const done = excuse.decisions.filter((d) => d.status !== 'pending').length;

    return excuse.decisions.length
        ? `${done} of ${excuse.decisions.length} decided`
        : 'No classes';
}
</script>

<template>
    <Head title="Excuses" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Excuses"
                :description="`${term ? `${term.label} · ` : ''}Your college's learners' excuses and how each class decided. Teachers decide for their classes; you decide only for classes without a teacher.`"
            />
            <TermPicker v-model="filters.term" :terms="terms" />
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
                {{ statusLabels[tab] }} ({{ counts[tab] }})
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
                        <th class="px-4 py-2 font-medium">Days</th>
                        <th class="px-4 py-2 font-medium">Reason</th>
                        <th class="px-4 py-2 font-medium">Classes</th>
                        <th class="px-4 py-2 font-medium">Submitted</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="excuse in excuses.data"
                        :key="excuse.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2">
                            <Link
                                :href="ExcuseController.show(excuse.id)"
                                class="font-medium text-highlight hover:underline"
                                >{{ excuse.learner.name }}</Link
                            >
                            <div class="text-xs text-muted-foreground">
                                {{ excuse.learner.student_number }} ·
                                {{ excuse.learner.program }}
                            </div>
                        </td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{
                                formatDateRange(
                                    excuse.date_from,
                                    excuse.date_to,
                                )
                            }}
                        </td>
                        <td class="px-4 py-2">
                            {{ reasonLabels[excuse.reason] }}
                        </td>
                        <td class="px-4 py-2">
                            {{ decided(excuse) }}
                            <Badge
                                v-if="excuse.dean_decides"
                                variant="default"
                                class="ml-2"
                                >{{ excuse.dean_decides }} yours</Badge
                            >
                        </td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{
                                excuse.submitted_on
                                    ? formatDate(excuse.submitted_on)
                                    : ''
                            }}
                            <Badge
                                v-if="excuse.days_late"
                                variant="secondary"
                                class="ml-2"
                                >{{ lateLabel(excuse.days_late) }}</Badge
                            >
                        </td>
                    </tr>
                    <tr v-if="excuses.data.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No
                            {{
                                statusLabels[
                                    filters.status as ExcuseStatus
                                ].toLowerCase()
                            }}
                            excuses.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="excuses" />
    </div>
</template>

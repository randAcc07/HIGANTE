<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { FileText, Plus } from '@lucide/vue';
import ExcuseController from '@/actions/App/Http/Controllers/Learner/ExcuseController';
import DecisionList from '@/components/excuses/DecisionList.vue';
import Heading from '@/components/Heading.vue';
import ExcuseFormDialog from '@/components/learner/ExcuseFormDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateRange } from '@/lib/dates';
import { fileSize, lateLabel, reasonLabels, statusLabels } from '@/lib/excuses';
import type { Excuse, ExcuseReason, ExcuseStatus } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Excuses', href: ExcuseController.index() }],
    },
});

defineProps<{
    excuses: Excuse[];
    reasons: ExcuseReason[];
    maxDays: number;
    dueWithinDays: number;
}>();

const badgeVariant: Record<
    ExcuseStatus,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    pending: 'secondary',
    approved: 'default',
    partial: 'outline',
    rejected: 'destructive',
    revoked: 'destructive',
};
</script>

<template>
    <Head title="Excuses" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Excuses"
                :description="`Ask your teachers to excuse absences, within ${dueWithinDays} days after them, and see how each request was decided`"
            />
            <ExcuseFormDialog
                :reasons="reasons"
                :max-days="maxDays"
                :due-within-days="dueWithinDays"
            >
                <Button><Plus class="h-4 w-4" /> Submit an excuse</Button>
            </ExcuseFormDialog>
        </div>

        <ul v-if="excuses.length" class="divide-y rounded-lg border">
            <li
                v-for="excuse in excuses"
                :key="excuse.id"
                class="space-y-2 p-4"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium">{{
                        formatDateRange(excuse.date_from, excuse.date_to)
                    }}</span>
                    <span class="text-muted-foreground"
                        >· {{ reasonLabels[excuse.reason] }}</span
                    >
                    <Badge :variant="badgeVariant[excuse.status]">{{
                        statusLabels[excuse.status]
                    }}</Badge>
                    <Badge v-if="excuse.days_late" variant="outline">{{
                        lateLabel(excuse.days_late)
                    }}</Badge>
                </div>
                <p v-if="excuse.remarks" class="text-sm">
                    {{ excuse.remarks }}
                </p>
                <ul v-if="excuse.documents.length" class="flex flex-wrap gap-3">
                    <li v-for="document in excuse.documents" :key="document.id">
                        <a
                            :href="document.url"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-1 text-sm text-highlight hover:underline"
                            ><FileText class="h-4 w-4" />
                            {{ document.name }}
                            <span class="text-muted-foreground"
                                >({{ fileSize(document.size) }})</span
                            ></a
                        >
                    </li>
                </ul>
                <DecisionList :decisions="excuse.decisions" />
                <p class="text-xs text-muted-foreground">
                    Submitted
                    {{
                        excuse.submitted_on
                            ? formatDate(excuse.submitted_on)
                            : ''
                    }}<template v-if="excuse.filed_by">
                        by {{ excuse.filed_by }}</template
                    >
                </p>
            </li>
        </ul>
        <p
            v-else
            class="rounded-lg border p-8 text-center text-sm text-muted-foreground"
        >
            No excuses yet. If you missed classes for a reason your school
            excuses, submit one with any document that supports it.
        </p>
    </div>
</template>

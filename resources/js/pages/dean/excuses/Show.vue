<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { FileText } from '@lucide/vue';
import ExcuseController from '@/actions/App/Http/Controllers/Dean/ExcuseController';
import DecisionList from '@/components/excuses/DecisionList.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { formatDate, formatDateRange } from '@/lib/dates';
import { fileSize, lateLabel, reasonLabels, statusLabels } from '@/lib/excuses';
import type { Excuse, ExcuseLearner } from '@/types';

const props = defineProps<{
    excuse: Excuse & { learner: ExcuseLearner };
    deciders: Record<number, { names: string[]; dean: boolean }>;
    lock: string | null;
}>();

setLayoutProps({
    breadcrumbs: [
        {
            title: 'Excuses',
            href: ExcuseController.index({
                query: { status: props.excuse.status },
            }),
        },
        {
            title: props.excuse.learner.name,
            href: ExcuseController.show(props.excuse.id),
        },
    ],
});
</script>

<template>
    <Head :title="`Excuse · ${excuse.learner.name}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="excuse.learner.name"
            :description="`${excuse.learner.student_number} · ${excuse.learner.program} · ${formatDateRange(excuse.date_from, excuse.date_to)} · ${reasonLabels[excuse.reason]}`"
        />

        <div class="flex flex-wrap items-center gap-2 text-sm">
            <Badge variant="outline">{{ statusLabels[excuse.status] }}</Badge>
            <Badge v-if="excuse.days_late" variant="secondary">{{
                lateLabel(excuse.days_late)
            }}</Badge>
            <span class="text-muted-foreground"
                >Submitted
                {{ excuse.submitted_on ? formatDate(excuse.submitted_on) : ''
                }}<template v-if="excuse.filed_by">
                    by {{ excuse.filed_by }}</template
                ></span
            >
        </div>

        <p
            v-if="lock"
            class="rounded-lg border p-3 text-sm text-muted-foreground"
        >
            {{ lock }}
        </p>

        <section class="space-y-2">
            <Heading variant="small" title="From the learner" />
            <p class="text-sm">{{ excuse.remarks || 'No remarks.' }}</p>
            <ul v-if="excuse.documents.length" class="flex flex-wrap gap-3">
                <li v-for="document in excuse.documents" :key="document.id">
                    <a
                        :href="document.url"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1 text-sm text-highlight hover:underline"
                        ><FileText class="h-4 w-4" /> {{ document.name }}
                        <span class="text-muted-foreground"
                            >({{ fileSize(document.size) }})</span
                        ></a
                    >
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">
                No files attached.
            </p>
        </section>

        <section class="space-y-2">
            <Heading
                variant="small"
                title="Classes"
                description="Each class's teacher decides it; open a class to see its absences"
            />
            <DecisionList
                :decisions="excuse.decisions"
                :deciders="deciders"
                linked
            />
        </section>
    </div>
</template>

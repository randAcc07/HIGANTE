<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { FileText } from '@lucide/vue';
import ExcuseController from '@/actions/App/Http/Controllers/Dean/ExcuseController';
import LearnerController from '@/actions/App/Http/Controllers/Dean/LearnerController';
import RemarksDialog from '@/components/dean/RemarksDialog.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateRange } from '@/lib/dates';
import { fileSize, lateLabel, reasonLabels, statusLabels } from '@/lib/excuses';
import { formatTime } from '@/lib/time';
import type { Excuse, ExcuseLearner } from '@/types';

const props = defineProps<{
    excuse: Excuse & {
        learner: ExcuseLearner;
        reviewed_by: string | null;
        needs_document: boolean;
    };
    absences: {
        id: number;
        date: string;
        start: string;
        subject: string;
        section: string;
        batch: string | null;
        /** Which excuse the absence is linked to. */
        excuse: 'this' | 'other' | 'none';
    }[];
    lates: number;
    dueWithinDays: number;
    /** Why it can't be decided now (a closed term), if so. */
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

const missingDocument =
    props.excuse.needs_document && props.excuse.documents.length === 0;
</script>

<template>
    <Head :title="`Excuse · ${excuse.learner.name}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="excuse.learner.name"
                :description="`${excuse.learner.student_number} · ${excuse.learner.program} · ${formatDateRange(excuse.date_from, excuse.date_to)} · ${reasonLabels[excuse.reason]}`"
            />
            <Button
                v-if="excuse.learner.registration_id"
                variant="outline"
                as-child
            >
                <Link
                    :href="
                        LearnerController.show(excuse.learner.registration_id)
                    "
                    >Learner's classes</Link
                >
            </Button>
        </div>

        <div class="flex flex-wrap items-center gap-2 text-sm">
            <Badge variant="outline">{{ statusLabels[excuse.status] }}</Badge>
            <Badge v-if="excuse.days_late" variant="secondary">{{
                lateLabel(excuse.days_late)
            }}</Badge>
            <span class="text-muted-foreground"
                >Submitted
                {{ excuse.submitted_on ? formatDate(excuse.submitted_on) : ''
                }}<template v-if="excuse.reviewed_on">
                    · decided {{ formatDate(excuse.reviewed_on) }}
                    <template v-if="excuse.reviewed_by"
                        >by {{ excuse.reviewed_by }}</template
                    ></template
                ></span
            >
        </div>

        <p
            v-if="excuse.days_late && excuse.status === 'pending'"
            class="rounded-lg border border-warning/50 bg-warning-soft p-3 text-sm text-warning-soft-foreground"
        >
            Submitted {{ excuse.days_late }} day{{
                excuse.days_late === 1 ? '' : 's'
            }}
            past the {{ dueWithinDays }}-day deadline. It can still be approved;
            weigh the delay.
        </p>

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
            <p
                v-else
                class="text-sm"
                :class="
                    missingDocument
                        ? 'text-destructive'
                        : 'text-muted-foreground'
                "
            >
                No files attached{{
                    missingDocument
                        ? ': a medical excuse needs its certificate before it can be approved.'
                        : '.'
                }}
            </p>
            <p
                v-if="excuse.review_remarks"
                class="rounded-md bg-muted/50 p-2 text-sm"
            >
                <span class="font-medium">Review:</span>
                {{ excuse.review_remarks }}
            </p>
            <p
                v-if="excuse.revoke_remarks"
                class="rounded-md bg-muted/50 p-2 text-sm"
            >
                <span class="font-medium">Revoked:</span>
                {{ excuse.revoke_remarks }}
            </p>
        </section>

        <section class="space-y-2">
            <Heading
                variant="small"
                title="Absences on these days"
                :description="`${absences.length} absence${absences.length === 1 ? '' : 's'} in all classes${lates ? ` · ${lates} late mark${lates === 1 ? '' : 's'} stay as they are` : ''}`"
            />
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Date</th>
                            <th class="px-4 py-2 font-medium">Class</th>
                            <th class="px-4 py-2 font-medium">Excused</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="absence in absences"
                            :key="absence.id"
                            class="border-t"
                        >
                            <td class="px-4 py-2 whitespace-nowrap">
                                {{ formatDate(absence.date) }} ·
                                {{ formatTime(absence.start) }}
                            </td>
                            <td class="px-4 py-2">
                                {{ absence.subject }}
                                <span class="text-muted-foreground"
                                    >· {{ absence.section
                                    }}<template v-if="absence.batch">
                                        ({{ absence.batch }})</template
                                    ></span
                                >
                            </td>
                            <td class="px-4 py-2">
                                <template v-if="absence.excuse === 'this'"
                                    >By this excuse</template
                                >
                                <template v-else-if="absence.excuse === 'other'"
                                    >By another excuse</template
                                >
                                <span v-else class="text-muted-foreground"
                                    >Not yet</span
                                >
                            </td>
                        </tr>
                        <tr v-if="absences.length === 0">
                            <td
                                colspan="3"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                No absences marked on these days yet. Absences
                                marked later are excused automatically once this
                                is approved.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div v-if="!lock" class="flex flex-wrap gap-2">
            <template v-if="excuse.status === 'pending'">
                <RemarksDialog
                    title="Approve this excuse?"
                    :description="`The learner's absences on ${formatDateRange(excuse.date_from, excuse.date_to)} are excused in every class, including any marked later. Lates stay.`"
                    confirm-label="Approve"
                    :url="ExcuseController.approve.url(excuse.id)"
                >
                    <Button :disabled="missingDocument">Approve</Button>
                </RemarksDialog>
                <RemarksDialog
                    title="Not approve this excuse?"
                    description="The learner sees your remarks."
                    confirm-label="Don't approve"
                    remarks-required
                    destructive
                    :url="ExcuseController.reject.url(excuse.id)"
                >
                    <Button variant="outline">Don't approve</Button>
                </RemarksDialog>
            </template>
            <RemarksDialog
                v-else-if="excuse.status === 'approved'"
                title="Revoke this excuse?"
                description="Its absences count again (unless another approved excuse covers the day), and the learner is told why."
                confirm-label="Revoke"
                remarks-required
                destructive
                :url="ExcuseController.revoke.url(excuse.id)"
            >
                <Button variant="outline">Revoke</Button>
            </RemarksDialog>
        </div>
    </div>
</template>

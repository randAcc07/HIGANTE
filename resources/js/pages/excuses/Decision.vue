<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { FileText } from '@lucide/vue';
import ExcuseDecisionController from '@/actions/App/Http/Controllers/ExcuseDecisionController';
import LearnerController from '@/actions/App/Http/Controllers/Dean/LearnerController';
import RemarksDialog from '@/components/dean/RemarksDialog.vue';
import DecisionList from '@/components/excuses/DecisionList.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateRange } from '@/lib/dates';
import {
    decisionLabels,
    fileSize,
    lateLabel,
    reasonLabels,
} from '@/lib/excuses';
import { formatTime } from '@/lib/time';
import type { Excuse, ExcuseDecision, ExcuseLearner } from '@/types';

const props = defineProps<{
    excuse: Excuse & { learner: ExcuseLearner; needs_document: boolean };
    decision: ExcuseDecision;
    absences: {
        id: number;
        date: string;
        start: string;
        batch: string | null;
        excuse: 'this' | 'other' | 'none';
    }[];
    lates: number;
    /** Why it can't be decided now (a closed term), if so. */
    lock: string | null;
    canDecide: boolean;
    dueWithinDays: number;
    back: string;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Excuses', href: props.back },
        {
            title: `${props.excuse.learner.name} · ${props.decision.subject.code}`,
            href: ExcuseDecisionController.show(props.decision.id),
        },
    ],
});

const missingDocument =
    props.excuse.needs_document && props.excuse.documents.length === 0;
const others = props.excuse.decisions.filter(
    (other) => other.id !== props.decision.id,
);
</script>

<template>
    <Head :title="`Excuse · ${excuse.learner.name}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="`${excuse.learner.name} · ${decision.subject.code}`"
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
            <Badge variant="outline">{{
                decisionLabels[decision.status]
            }}</Badge>
            <Badge v-if="excuse.days_late" variant="secondary">{{
                lateLabel(excuse.days_late)
            }}</Badge>
            <span class="text-muted-foreground"
                >Submitted
                {{ excuse.submitted_on ? formatDate(excuse.submitted_on) : ''
                }}<template v-if="excuse.filed_by">
                    by {{ excuse.filed_by }}</template
                ><template v-if="decision.decided_by">
                    · decided by {{ decision.decided_by }}
                    {{
                        decision.decided_on
                            ? formatDate(decision.decided_on)
                            : ''
                    }}</template
                ></span
            >
        </div>

        <p
            v-if="excuse.days_late && decision.status === 'pending'"
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
        <p
            v-else-if="!canDecide"
            class="rounded-lg border p-3 text-sm text-muted-foreground"
        >
            This class's teacher decides it; you can follow along here.
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
        </section>

        <section class="space-y-2">
            <Heading
                variant="small"
                :title="`Absences in ${decision.subject.code} on these days`"
                :description="`${absences.length} absence${absences.length === 1 ? '' : 's'}${lates ? ` · ${lates} late mark${lates === 1 ? '' : 's'} stay as they are` : ''}`"
            />
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Date</th>
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
                                {{ formatTime(absence.start)
                                }}<template v-if="absence.batch">
                                    ({{ absence.batch }})</template
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
                                colspan="2"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                No absences in this class on these days yet.
                                Absences marked later are excused automatically
                                once this is approved.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div v-if="canDecide && !lock" class="flex flex-wrap gap-2">
            <template v-if="decision.status === 'pending'">
                <RemarksDialog
                    :title="`Approve for ${decision.subject.code}?`"
                    :description="`The learner's absences in ${decision.subject.code} on ${formatDateRange(excuse.date_from, excuse.date_to)} are excused, including any marked later. Lates stay. Their other classes are decided by their teachers.`"
                    confirm-label="Approve"
                    :url="ExcuseDecisionController.approve.url(decision.id)"
                >
                    <Button :disabled="missingDocument">Approve</Button>
                </RemarksDialog>
                <RemarksDialog
                    :title="`Not approve for ${decision.subject.code}?`"
                    description="The learner sees your remarks."
                    confirm-label="Don't approve"
                    remarks-required
                    destructive
                    :url="ExcuseDecisionController.decline.url(decision.id)"
                >
                    <Button variant="outline">Don't approve</Button>
                </RemarksDialog>
            </template>
            <RemarksDialog
                v-else-if="decision.status === 'approved'"
                :title="`Revoke for ${decision.subject.code}?`"
                description="Its absences in this class count again (unless another approved excuse covers the day), and the learner is told why."
                confirm-label="Revoke"
                remarks-required
                destructive
                :url="ExcuseDecisionController.revoke.url(decision.id)"
            >
                <Button variant="outline">Revoke</Button>
            </RemarksDialog>
        </div>

        <section v-if="others.length" class="space-y-2">
            <Heading
                variant="small"
                title="Other classes"
                description="Each decided by its own teacher"
            />
            <DecisionList :decisions="others" />
        </section>
    </div>
</template>

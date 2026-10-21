<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ExcuseDecisionController from '@/actions/App/Http/Controllers/ExcuseDecisionController';
import { Badge } from '@/components/ui/badge';
import { formatDate } from '@/lib/dates';
import { decisionLabels } from '@/lib/excuses';
import type { DecisionStatus, ExcuseDecision } from '@/types';

/** An excuse's classes and how each decided. */
defineProps<{
    decisions: ExcuseDecision[];
    /** Link each class to its decision page (teachers and the dean). */
    linked?: boolean;
    /** Per decision: who decides it (the dean's overview). */
    deciders?: Record<number, { names: string[]; dean: boolean }>;
}>();

const variants: Record<
    DecisionStatus,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    pending: 'secondary',
    approved: 'default',
    declined: 'destructive',
    revoked: 'destructive',
};
</script>

<template>
    <ul v-if="decisions.length" class="divide-y rounded-md border">
        <li
            v-for="decision in decisions"
            :key="decision.id"
            class="flex flex-wrap items-start justify-between gap-2 p-3 text-sm"
        >
            <div class="space-y-1">
                <div>
                    <Link
                        v-if="linked"
                        :href="ExcuseDecisionController.show(decision.id)"
                        class="font-medium text-highlight hover:underline"
                        >{{ decision.subject.code }}</Link
                    >
                    <span v-else class="font-medium">{{
                        decision.subject.code
                    }}</span>
                    <span class="text-muted-foreground">
                        · {{ decision.section }}</span
                    >
                </div>
                <p v-if="decision.remarks" class="text-muted-foreground">
                    “{{ decision.remarks }}”
                </p>
                <p v-if="decision.revoke_remarks" class="text-muted-foreground">
                    Revoked: “{{ decision.revoke_remarks }}”
                </p>
                <p
                    v-if="
                        decision.status === 'pending' && deciders?.[decision.id]
                    "
                    class="text-xs text-muted-foreground"
                >
                    {{
                        deciders[decision.id].dean
                            ? 'No teacher yet: yours to decide'
                            : `Decided by ${deciders[decision.id].names.join(' or ') || 'its teacher'}`
                    }}
                </p>
            </div>
            <div class="text-right">
                <Badge :variant="variants[decision.status]">{{
                    decisionLabels[decision.status]
                }}</Badge>
                <div
                    v-if="decision.decided_by"
                    class="mt-1 text-xs text-muted-foreground"
                >
                    {{ decision.decided_by
                    }}<template v-if="decision.decided_on">
                        · {{ formatDate(decision.decided_on) }}</template
                    >
                </div>
            </div>
        </li>
    </ul>
    <p v-else class="text-sm text-muted-foreground">
        None of the learner's classes meet on these days, so there is nothing to
        excuse.
    </p>
</template>

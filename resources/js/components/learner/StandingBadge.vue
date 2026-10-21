<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { LearnerClass } from '@/types';

/** "Dropped", "For dropping", "Warned at N", or "Good standing". */
defineProps<{
    item: Pick<LearnerClass, 'status' | 'warning_level' | 'drop_reason'>;
}>();

const dropLabels: Record<NonNullable<LearnerClass['drop_reason']>, string> = {
    absences: 'Dropped for absences',
    withdrawn: 'Withdrawn',
    removed: 'Removed from your load',
};
</script>

<template>
    <Badge v-if="item.status === 'dropped'" variant="secondary">{{
        item.drop_reason ? dropLabels[item.drop_reason] : 'Dropped'
    }}</Badge>
    <Badge v-else-if="item.status === 'for_dropping'" variant="destructive"
        >For dropping</Badge
    >
    <Badge v-else-if="item.warning_level" variant="outline"
        >Warned at {{ item.warning_level }}</Badge
    >
    <span v-else class="text-sm text-muted-foreground">Good standing</span>
</template>

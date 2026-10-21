<script setup lang="ts">
import { ArrowRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';

/**
 * Beside the term picker on pages that prepare a term (sections,
 * timetables, registration): a shortcut to the next term while the page
 * shows another (it opens on the term in session).
 */
defineProps<{
    term: { id: number; label: string };
}>();

const emit = defineEmits<{ pick: [id: number] }>();
</script>

<template>
    <Button
        variant="outline"
        class="h-9"
        :title="`Open ${term.label}`"
        @click="emit('pick', term.id)"
    >
        Next active term <ArrowRight class="h-4 w-4" />
    </Button>
</template>

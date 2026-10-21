<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { addDays } from '@/lib/days';

/** Steps a "Y-m-d" date by a day, or jumps to today or any date. */
const date = defineModel<string>({ required: true });

defineProps<{ today: string }>();
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <Button
            variant="outline"
            size="sm"
            aria-label="Previous day"
            @click="date = addDays(date, -1)"
        >
            <ChevronLeft class="h-4 w-4" />
        </Button>
        <input
            v-model="date"
            type="date"
            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
            aria-label="Date"
        />
        <Button
            variant="outline"
            size="sm"
            aria-label="Next day"
            @click="date = addDays(date, 1)"
        >
            <ChevronRight class="h-4 w-4" />
        </Button>
        <Button
            v-if="date !== today"
            variant="ghost"
            size="sm"
            @click="date = today"
            >Today</Button
        >
    </div>
</template>

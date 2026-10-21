<script setup lang="ts">
/** The term a page shows, picked from all terms (hidden while there is only one). */
defineProps<{
    terms: { id: number; label: string }[];
}>();

const term = defineModel<number | null>({ required: true });
</script>

<template>
    <select
        v-if="terms.length > 1"
        v-model="term"
        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
        aria-label="Term"
    >
        <option v-for="option in terms" :key="option.id" :value="option.id">
            {{ option.label }}
        </option>
    </select>
</template>

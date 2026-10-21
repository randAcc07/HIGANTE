<script setup lang="ts">
import { NativeSelect } from '@/components/ui/native-select';
/** The term a page shows, picked from all terms (hidden while there is only one). */
defineProps<{
    terms: { id: number; label: string }[];
}>();

const term = defineModel<number | null>({ required: true });
</script>

<template>
    <NativeSelect v-if="terms.length > 1" v-model="term" aria-label="Term">
        <option v-for="option in terms" :key="option.id" :value="option.id">
            {{ option.label }}
        </option>
    </NativeSelect>
</template>

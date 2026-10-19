<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Paginated } from '@/types';

defineProps<{
    paginator: Paginated<unknown>;
}>();

/** Laravel labels the ends "&laquo; Previous" and "Next &raquo;". */
function label(text: string): string {
    return text.replace('&laquo;', '‹').replace('&raquo;', '›');
}
</script>

<template>
    <nav
        v-if="paginator.last_page > 1"
        class="flex flex-wrap items-center justify-between gap-2 text-sm"
        aria-label="Pagination"
    >
        <p class="text-muted-foreground">
            {{ paginator.from }}–{{ paginator.to }} of {{ paginator.total }}
        </p>
        <div class="flex flex-wrap gap-1">
            <template v-for="link in paginator.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    preserve-state
                    class="rounded-md border px-3 py-1"
                    :class="
                        link.active
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'hover:bg-muted'
                    "
                    >{{ label(link.label) }}</Link
                >
                <span
                    v-else
                    class="rounded-md border px-3 py-1 text-muted-foreground opacity-50"
                    >{{ label(link.label) }}</span
                >
            </template>
        </div>
    </nav>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';

/** A side list on the dashboard: a title with a count, items, and a link to the full page. */
defineProps<{
    title: string;
    count?: number;
    href?: InertiaLinkProps['href'];
    /** Shown when the list is empty. */
    empty: string;
    isEmpty: boolean;
}>();
</script>

<template>
    <section class="rounded-xl border">
        <div class="flex items-center justify-between gap-2 border-b px-4 py-3">
            <h2 class="text-sm font-medium">
                {{ title }}
                <span
                    v-if="count"
                    class="ml-1 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                    >{{ count }}</span
                >
            </h2>
            <Link
                v-if="href"
                :href="href"
                class="text-xs text-highlight hover:underline"
                >See all</Link
            >
        </div>
        <p
            v-if="isEmpty"
            class="px-4 py-6 text-center text-sm text-muted-foreground"
        >
            {{ empty }}
        </p>
        <ul v-else class="divide-y">
            <slot />
        </ul>
    </section>
</template>

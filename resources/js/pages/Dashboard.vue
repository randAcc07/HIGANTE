<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const props = defineProps<{
    roleLabel: string;
    collegeName: string | null;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);

const scope = computed(() =>
    props.collegeName
        ? `${props.roleLabel} · ${props.collegeName}`
        : `${props.roleLabel} · Campus-wide`,
);
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <Heading :title="`Welcome, ${user.first_name}`" :description="scope" />

        <div
            class="rounded-xl border border-sidebar-border/70 p-6 text-sm text-muted-foreground dark:border-sidebar-border"
        >
            Your tools will appear here as they are added.
        </div>
    </div>
</template>

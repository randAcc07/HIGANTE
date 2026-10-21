<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ClassController from '@/actions/App/Http/Controllers/Learner/ClassController';
import ExcuseController from '@/actions/App/Http/Controllers/Learner/ExcuseController';
import Heading from '@/components/Heading.vue';
import StandingBadge from '@/components/learner/StandingBadge.vue';
import TextLink from '@/components/TextLink.vue';
import { dashboard } from '@/routes';
import type { LearnerClass } from '@/types';

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
    learner: {
        term: string | null;
        attention: (Pick<
            LearnerClass,
            | 'enrollment_id'
            | 'subject'
            | 'section'
            | 'status'
            | 'warning_level'
            | 'drop_reason'
        > & { equivalent: number; drop_at: number })[];
        pendingExcuses: number;
    } | null;
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

        <template v-if="learner">
            <section class="space-y-3">
                <Heading
                    variant="small"
                    title="Needs your attention"
                    :description="learner.term ?? ''"
                />
                <ul
                    v-if="learner.attention.length"
                    class="divide-y rounded-lg border"
                >
                    <li
                        v-for="item in learner.attention"
                        :key="item.enrollment_id"
                        class="flex flex-wrap items-center justify-between gap-3 p-4"
                    >
                        <div>
                            <Link
                                :href="ClassController.show(item.enrollment_id)"
                                class="font-medium text-highlight hover:underline"
                                >{{ item.subject.code }}</Link
                            >
                            <span class="text-muted-foreground">
                                · {{ item.section }}</span
                            >
                            <div class="text-sm text-muted-foreground">
                                {{ item.equivalent }} of {{ item.drop_at }}
                                equivalent absences
                            </div>
                        </div>
                        <StandingBadge :item="item" />
                    </li>
                </ul>
                <p
                    v-else
                    class="rounded-lg border p-4 text-sm text-muted-foreground"
                >
                    No warnings: you're in good standing in every class.
                    <TextLink :href="ClassController.index()"
                        >See your classes</TextLink
                    >
                </p>
                <p
                    v-if="learner.pendingExcuses"
                    class="text-sm text-muted-foreground"
                >
                    {{ learner.pendingExcuses }} excuse{{
                        learner.pendingExcuses === 1 ? ' is' : 's are'
                    }}
                    waiting for your dean.
                    <TextLink :href="ExcuseController.index()"
                        >See excuses</TextLink
                    >
                </p>
            </section>
        </template>
        <div
            v-else
            class="rounded-xl border border-sidebar-border/70 p-6 text-sm text-muted-foreground dark:border-sidebar-border"
        >
            Your tools will appear here as they are added.
        </div>
    </div>
</template>

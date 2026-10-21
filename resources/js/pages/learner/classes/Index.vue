<script setup lang="ts">
import { Eye } from '@lucide/vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import ClassController from '@/actions/App/Http/Controllers/Learner/ClassController';
import Heading from '@/components/Heading.vue';
import StandingBadge from '@/components/learner/StandingBadge.vue';
import ScheduleGrid from '@/components/ScheduleGrid.vue';
import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/native-select';
import type {
    AttendancePolicy,
    ClassBlock,
    GridBlock,
    LearnerClass,
    StandingCounts,
    TermPhase,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'My classes', href: ClassController.index() }],
    },
});

type TermOption = { id: number; label: string; phase: TermPhase };

const props = defineProps<{
    terms: TermOption[];
    term: TermOption | null;
    registration: {
        year_level: number;
        section: string | null;
        status: 'registered' | 'withdrawn';
    } | null;
    policy: AttendancePolicy | null;
    classes: (LearnerClass & {
        counts: StandingCounts;
        periods: { held: number; total: number };
    })[];
    week: ClassBlock[];
}>();

const phaseLabels: Record<TermPhase, string> = {
    upcoming: 'Upcoming',
    in_session: 'In session',
    ended: 'Ended',
    closed: 'Closed',
};

const description = computed(() => {
    if (!props.term) {
        return "You aren't registered for any term yet.";
    }

    const parts = [`${props.term.label} · ${phaseLabels[props.term.phase]}`];

    if (props.registration) {
        parts.push(`Year ${props.registration.year_level}`);
        parts.push(
            props.registration.section
                ? `Section ${props.registration.section}`
                : 'No section yet',
        );
    }

    return parts.join(' · ');
});

function showTerm(event: Event): void {
    router.get(ClassController.index.url(), {
        term: Number((event.target as HTMLSelectElement).value),
    });
}

const blocks = computed<GridBlock[]>(() => {
    const tones = new Map<string, number>();

    return props.week.map((item) => {
        if (!tones.has(item.subject)) {
            tones.set(item.subject, tones.size);
        }

        return {
            key: `class-${item.id}`,
            id: item.id,
            weekday: item.weekday,
            start: item.start,
            end: item.end,
            title: item.batch
                ? `${item.subject} · ${item.batch}`
                : item.subject,
            lines: [item.room ?? 'Online', item.teacher],
            tone: tones.get(item.subject) ?? 0,
            group: item.batch ?? '',
        };
    });
});
</script>

<template>
    <Head title="My classes" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading title="My classes" :description="description" />
            <NativeSelect
                v-if="terms.length > 1"
                :value="term?.id"
                aria-label="Term"
                @change="showTerm"
            >
                <option
                    v-for="option in terms"
                    :key="option.id"
                    :value="option.id"
                >
                    {{ option.label }} ({{ phaseLabels[option.phase] }})
                </option>
            </NativeSelect>
        </div>

        <p
            v-if="registration?.status === 'withdrawn'"
            class="rounded-lg border p-3 text-sm text-muted-foreground"
        >
            You withdrew from this term; your classes were dropped.
        </p>

        <section v-if="term" class="space-y-3">
            <Heading
                variant="small"
                title="Attendance"
                :description="
                    policy
                        ? `${policy.lates_per_absence} lates count as 1 absence. You are warned at ${policy.warn_at_absences.join(' and ')} equivalent absences and flagged for dropping at ${policy.drop_at_absences}. Excused absences don't count.`
                        : ''
                "
            />
            <div v-table-scroll class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Class</th>
                            <th class="px-4 py-2 font-medium">Teacher</th>
                            <th class="px-4 py-2 font-medium">
                                Equivalent absences
                            </th>
                            <th class="px-4 py-2 font-medium">Standing</th>
                            <th class="table-actions px-4 py-2">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in classes"
                            :key="item.enrollment_id"
                            class="border-t"
                            :class="{
                                'text-muted-foreground':
                                    item.status === 'dropped',
                            }"
                        >
                            <td class="px-4 py-2">
                                <span class="font-medium">{{
                                    item.subject.code
                                }}</span>
                                <span class="text-muted-foreground">
                                    · {{ item.section
                                    }}<template v-if="item.batch">
                                        ({{ item.batch }})</template
                                    ></span
                                >
                                <div class="text-xs text-muted-foreground">
                                    {{ item.subject.title }}
                                </div>
                            </td>
                            <td class="px-4 py-2">
                                {{ item.teacher ?? 'To be announced' }}
                            </td>
                            <td class="px-4 py-2">
                                <span class="font-medium">{{
                                    item.counts.equivalent
                                }}</span>
                                <span
                                    v-if="policy"
                                    class="text-muted-foreground"
                                >
                                    of {{ policy.drop_at_absences }}</span
                                >
                                <div class="text-xs text-muted-foreground">
                                    {{ item.counts.absences }} absent ·
                                    {{ item.counts.lates }} late<template
                                        v-if="item.counts.excused"
                                    >
                                        ·
                                        {{ item.counts.excused }}
                                        excused</template
                                    >
                                    · {{ item.periods.held }} of
                                    {{ item.periods.total }} classes so far
                                </div>
                            </td>
                            <td class="px-4 py-2">
                                <StandingBadge :item="item" />
                            </td>
                            <td class="table-actions px-4 py-2">
                                <div class="flex justify-end gap-1">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        as-child
                                        title="View class"
                                    >
                                        <Link
                                            :href="
                                                ClassController.show(
                                                    item.enrollment_id,
                                                )
                                            "
                                        >
                                            <Eye class="h-4 w-4" />
                                            <span class="sr-only"
                                                >View class</span
                                            >
                                        </Link>
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="classes.length === 0">
                            <td
                                colspan="5"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                No classes in this term yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="term" class="space-y-3">
            <ScheduleGrid :blocks="blocks">
                <template #heading>
                    <Heading
                        variant="small"
                        title="Week"
                        description="Your classes, including your batches' labs"
                    />
                </template>
            </ScheduleGrid>
        </section>
    </div>
</template>

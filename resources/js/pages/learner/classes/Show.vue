<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import ClassController from '@/actions/App/Http/Controllers/Learner/ClassController';
import ExcuseController from '@/actions/App/Http/Controllers/Learner/ExcuseController';
import Heading from '@/components/Heading.vue';
import StandingBadge from '@/components/learner/StandingBadge.vue';
import TextLink from '@/components/TextLink.vue';
import { Badge } from '@/components/ui/badge';
import { formatDate } from '@/lib/dates';
import { formatTime } from '@/lib/time';
import type { AttendancePolicy, LearnerClass, StandingCounts } from '@/types';

const props = defineProps<{
    /** The class (not "class": Vue keeps that name for the attribute). */
    item: LearnerClass;
    term: { id: number; label: string };
    policy: AttendancePolicy;
    counts: StandingCounts;
    periods: { held: number; total: number };
    marks: {
        id: number;
        date: string;
        start: string;
        end: string;
        status: 'late' | 'absent';
        excused: boolean;
        /** The suspension entered after this mark, if any (it doesn't count). */
        suspended: string | null;
    }[];
}>();

setLayoutProps({
    breadcrumbs: [
        {
            title: 'My classes',
            href: ClassController.index({ query: { term: props.term.id } }),
        },
        {
            title: props.item.subject.code,
            href: ClassController.show(props.item.enrollment_id),
        },
    ],
});
</script>

<template>
    <Head :title="props.item.subject.code" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="`${props.item.subject.code} · ${props.item.section}${props.item.batch ? ` (${props.item.batch})` : ''}`"
                :description="`${props.item.subject.title} · ${term.label} · ${props.item.teacher ?? 'Teacher to be announced'}`"
            />
            <StandingBadge :item="props.item" />
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-lg border p-4">
                <div class="text-sm text-muted-foreground">
                    Equivalent absences
                </div>
                <div class="text-2xl font-semibold">
                    {{ counts.equivalent }}
                    <span class="text-base font-normal text-muted-foreground"
                        >of {{ policy.drop_at_absences }}</span
                    >
                </div>
                <div class="text-xs text-muted-foreground">
                    {{ counts.absences }} absent + {{ counts.lates }} late ÷
                    {{ policy.lates_per_absence }}
                </div>
            </div>
            <div class="rounded-lg border p-4">
                <div class="text-sm text-muted-foreground">Excused</div>
                <div class="text-2xl font-semibold">{{ counts.excused }}</div>
                <div class="text-xs text-muted-foreground">
                    Absences an approved excuse covers don't count
                </div>
            </div>
            <div class="rounded-lg border p-4">
                <div class="text-sm text-muted-foreground">Classes so far</div>
                <div class="text-2xl font-semibold">
                    {{ periods.held }}
                    <span class="text-base font-normal text-muted-foreground"
                        >of {{ periods.total }}</span
                    >
                </div>
                <div class="text-xs text-muted-foreground">
                    In the term, not counting suspended days
                </div>
            </div>
        </div>

        <section class="space-y-3">
            <Heading
                variant="small"
                title="Late and absent days"
                description="You count as present on every other class day"
            />
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Date</th>
                            <th class="px-4 py-2 font-medium">Mark</th>
                            <th class="px-4 py-2 font-medium">Counts</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="mark in marks"
                            :key="mark.id"
                            class="border-t"
                        >
                            <td class="px-4 py-2 whitespace-nowrap">
                                {{
                                    formatDate(mark.date, {
                                        weekday: 'short',
                                        month: 'short',
                                        day: 'numeric',
                                    })
                                }}
                                <span class="text-muted-foreground"
                                    >· {{ formatTime(mark.start) }}–{{
                                        formatTime(mark.end)
                                    }}</span
                                >
                            </td>
                            <td class="px-4 py-2">
                                <Badge
                                    :variant="
                                        mark.status === 'absent'
                                            ? 'destructive'
                                            : 'secondary'
                                    "
                                    >{{
                                        mark.status === 'absent'
                                            ? 'Absent'
                                            : 'Late'
                                    }}</Badge
                                >
                            </td>
                            <td class="px-4 py-2 text-muted-foreground">
                                <template v-if="mark.suspended"
                                    >No: classes were suspended ({{
                                        mark.suspended
                                    }})</template
                                >
                                <template v-else-if="mark.excused"
                                    >No: excused</template
                                >
                                <template v-else-if="mark.status === 'late'"
                                    >As 1/{{ policy.lates_per_absence }} of an
                                    absence</template
                                >
                                <template v-else>Yes</template>
                            </td>
                        </tr>
                        <tr v-if="marks.length === 0">
                            <td
                                colspan="3"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                No late or absent marks: present at every class
                                so far.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-sm text-muted-foreground">
                Missed a class for a reason the school excuses?
                <TextLink :href="ExcuseController.index()"
                    >Submit an excuse</TextLink
                >
                within 7 days.
            </p>
        </section>
    </div>
</template>

<script setup lang="ts">
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Lock } from '@lucide/vue';
import { computed, reactive, watch } from 'vue';
import AttendanceController from '@/actions/App/Http/Controllers/AttendanceController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/dates';
import type { AttendanceStatus, ClassListLearner } from '@/types';

const props = defineProps<{
    schedule: {
        id: number;
        subject: { code: string; title: string };
        section: string;
        batch: string | null;
        room: string | null;
        teacher: string;
        when: string;
        term: string;
    };
    date: string;
    refusal: string | null;
    /** The day was suspended after marks were made: shown, not counted. */
    suspendedAfter: boolean;
    learners: ClassListLearner[];
    previous: string | null;
    next: string | null;
    canEdit: boolean;
    /** Why the teacher can no longer change this day (the term's limit), or null. */
    teacherLock: string | null;
    /** The substitute covering the class that day, if any. */
    cover: { substitute: string; range: string } | null;
    /** Whether the viewer is that substitute. */
    covering: boolean;
    closed: boolean;
    back: string;
    backLabel: string;
}>();

const title = computed(
    () =>
        `${props.schedule.subject.code} · ${props.schedule.section}${props.schedule.batch ? ` (${props.schedule.batch})` : ''}`,
);
const longDate = computed(() =>
    formatDate(props.date, {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: 'numeric',
    }),
);

setLayoutProps({
    breadcrumbs: [
        { title: props.backLabel, href: props.back },
        {
            title: title.value,
            href: AttendanceController.show({
                schedule: props.schedule.id,
                date: props.date,
            }),
        },
    ],
});

const statuses: { value: AttendanceStatus; label: string; on: string }[] = [
    {
        value: 'present',
        label: 'Present',
        on: 'border-success bg-success-soft text-success-soft-foreground',
    },
    {
        value: 'late',
        label: 'Late',
        on: 'border-warning bg-warning-soft text-warning-soft-foreground',
    },
    {
        value: 'absent',
        label: 'Absent',
        on: 'border-destructive bg-destructive/10 text-destructive',
    },
];

/** Enrollment id → the mark on screen; no mark counts as present. */
const marks = reactive<Record<number, AttendanceStatus>>({});

watch(
    () => props.learners,
    (learners) => {
        for (const key of Object.keys(marks)) {
            delete marks[Number(key)];
        }

        for (const learner of learners) {
            marks[learner.enrollment_id] = learner.status ?? 'present';
        }
    },
    { immediate: true },
);

/** Marks that differ from what is saved (everything, before the first save). */
const changed = computed(() =>
    props.learners.filter(
        (learner) =>
            !learner.excused &&
            marks[learner.enrollment_id] !== (learner.status ?? 'present'),
    ),
);

const counts = computed(() =>
    statuses.map((status) => ({
        ...status,
        count: props.learners.filter(
            (learner) => marks[learner.enrollment_id] === status.value,
        ).length,
    })),
);

function markAll(status: AttendanceStatus): void {
    for (const learner of props.learners) {
        if (!learner.excused) {
            marks[learner.enrollment_id] = status;
        }
    }
}

const form = useForm<{
    marks: { enrollment_id: number; status: AttendanceStatus }[];
}>({ marks: [] });

function save(): void {
    form.marks = changed.value.map((learner) => ({
        enrollment_id: learner.enrollment_id,
        status: marks[learner.enrollment_id],
    }));
    form.put(
        AttendanceController.update.url({
            schedule: props.schedule.id,
            date: props.date,
        }),
        { preserveScroll: true },
    );
}

function recorded(learner: ClassListLearner): string {
    if (!learner.recorded_at) {
        return '';
    }

    const at = new Date(learner.recorded_at).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });

    return learner.recorded_by ? `${learner.recorded_by}, ${at}` : at;
}
</script>

<template>
    <Head :title="`${title} · ${date}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="title"
                :description="`${schedule.subject.title} · ${longDate} · ${schedule.when} · ${schedule.room ?? 'Online'} · ${schedule.teacher}`"
            />
            <div class="flex gap-2">
                <Button v-if="previous" variant="outline" size="sm" as-child>
                    <Link
                        :href="
                            AttendanceController.show({
                                schedule: schedule.id,
                                date: previous,
                            })
                        "
                    >
                        <ChevronLeft class="h-4 w-4" /> Previous meeting
                    </Link>
                </Button>
                <Button v-if="next" variant="outline" size="sm" as-child>
                    <Link
                        :href="
                            AttendanceController.show({
                                schedule: schedule.id,
                                date: next,
                            })
                        "
                    >
                        Next meeting <ChevronRight class="h-4 w-4" />
                    </Link>
                </Button>
            </div>
        </div>

        <p
            v-if="cover"
            class="rounded-lg border p-3 text-sm text-muted-foreground"
        >
            <template v-if="covering"
                >You cover this class for {{ schedule.teacher }} on
                {{ cover.range }}. Excuses stay with them.</template
            >
            <template v-else
                >{{ cover.substitute }} covers this class on
                {{ cover.range }}.</template
            >
        </p>

        <p
            v-if="refusal"
            class="rounded-lg border border-info/50 bg-info-soft p-3 text-sm text-info-soft-foreground"
        >
            {{ refusal }}
            <template v-if="suspendedAfter">
                These marks were made before the suspension was entered; they
                are kept on record but no longer count.</template
            >
        </p>

        <template v-if="!refusal || suspendedAfter">
            <p
                v-if="closed"
                class="rounded-lg border p-3 text-sm text-muted-foreground"
            >
                {{ schedule.term }} is closed, so its attendance is read-only.
            </p>
            <p
                v-else-if="teacherLock"
                class="rounded-lg border p-3 text-sm text-muted-foreground"
            >
                {{ teacherLock }}
            </p>
            <p
                v-else-if="canEdit && learners.length"
                class="text-sm text-muted-foreground"
            >
                Everyone counts as present unless marked otherwise. Mark who was
                late or absent and save; every change is kept on record.
            </p>

            <div
                v-if="Object.keys(form.errors).length"
                class="rounded-lg border border-destructive/50 p-3 text-sm text-destructive"
                role="alert"
            >
                <p v-for="error in Object.values(form.errors)" :key="error">
                    {{ error }}
                </p>
            </div>

            <div
                v-if="learners.length"
                class="flex flex-wrap items-center justify-between gap-3"
            >
                <div class="flex flex-wrap gap-3 text-sm">
                    <span v-for="status in counts" :key="status.value">
                        {{ status.label }}: {{ status.count }}
                    </span>
                    <span class="text-muted-foreground"
                        >of {{ learners.length }}</span
                    >
                </div>
                <Button
                    v-if="canEdit"
                    variant="outline"
                    size="sm"
                    @click="markAll('present')"
                    >Mark everyone present</Button
                >
            </div>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Learner</th>
                            <th class="px-4 py-2 font-medium">Mark</th>
                            <th class="px-4 py-2 font-medium">Recorded</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="learner in learners"
                            :key="learner.enrollment_id"
                            class="border-t"
                        >
                            <td class="px-4 py-2">
                                <div class="font-medium">
                                    {{ learner.name }}
                                    <Badge
                                        v-if="
                                            learner.standing === 'for_dropping'
                                        "
                                        variant="destructive"
                                        class="ml-2"
                                        >For dropping</Badge
                                    >
                                    <Badge
                                        v-else-if="
                                            learner.standing === 'dropped'
                                        "
                                        variant="secondary"
                                        class="ml-2"
                                        >Dropped</Badge
                                    >
                                    <Badge
                                        v-else-if="learner.warning_level"
                                        variant="outline"
                                        class="ml-2"
                                        >Warned at
                                        {{ learner.warning_level }}</Badge
                                    >
                                </div>
                                <div
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ learner.student_number }}
                                </div>
                            </td>
                            <td class="px-4 py-2">
                                <Badge v-if="learner.excused" variant="outline"
                                    ><Lock class="h-3 w-3" /> Excused
                                    absence</Badge
                                >
                                <div
                                    v-else
                                    class="inline-flex gap-1"
                                    role="radiogroup"
                                    :aria-label="`${learner.name}'s mark`"
                                >
                                    <button
                                        v-for="status in statuses"
                                        :key="status.value"
                                        type="button"
                                        role="radio"
                                        :aria-checked="
                                            marks[learner.enrollment_id] ===
                                            status.value
                                        "
                                        :disabled="!canEdit"
                                        class="rounded-md border px-3 py-1 text-sm transition-colors disabled:cursor-not-allowed"
                                        :class="
                                            marks[learner.enrollment_id] ===
                                            status.value
                                                ? status.on
                                                : 'border-input text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                                        "
                                        @click="
                                            marks[learner.enrollment_id] =
                                                status.value
                                        "
                                    >
                                        {{ status.label }}
                                    </button>
                                </div>
                            </td>
                            <td
                                class="px-4 py-2 text-xs whitespace-nowrap text-muted-foreground"
                            >
                                <template v-if="learner.status">{{
                                    recorded(learner)
                                }}</template>
                                <template v-else>—</template>
                            </td>
                        </tr>
                        <tr v-if="learners.length === 0">
                            <td
                                colspan="3"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                Nobody was enrolled in this class on
                                {{ longDate }}.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="canEdit && learners.length"
                class="sticky bottom-0 flex items-center justify-end gap-3 border-t bg-background py-3"
            >
                <span class="text-sm text-muted-foreground"
                    >{{ changed.length }} unsaved change{{
                        changed.length === 1 ? '' : 's'
                    }}</span
                >
                <Button
                    :disabled="changed.length === 0 || form.processing"
                    @click="save"
                    >Save changes</Button
                >
            </div>
        </template>
    </div>
</template>

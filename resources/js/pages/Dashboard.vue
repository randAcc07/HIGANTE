<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CalendarController from '@/actions/App/Http/Controllers/CalendarController';
import DeanExcuseController from '@/actions/App/Http/Controllers/Dean/ExcuseController';
import StandingController from '@/actions/App/Http/Controllers/Dean/StandingController';
import ExcuseDecisionController from '@/actions/App/Http/Controllers/ExcuseDecisionController';
import ClassController from '@/actions/App/Http/Controllers/Learner/ClassController';
import ExcuseController from '@/actions/App/Http/Controllers/Learner/ExcuseController';
import TeacherExcuseController from '@/actions/App/Http/Controllers/Teacher/ExcuseController';
import SidePanel from '@/components/dashboard/SidePanel.vue';
import WeekPanel from '@/components/dashboard/WeekPanel.vue';
import Heading from '@/components/Heading.vue';
import StandingBadge from '@/components/learner/StandingBadge.vue';
import { Badge } from '@/components/ui/badge';
import { formatDateRange } from '@/lib/dates';
import { lateLabel, reasonLabels, statusLabels } from '@/lib/excuses';
import { formatTime } from '@/lib/time';
import { dashboard } from '@/routes';
import type {
    DatedWeek,
    ExcuseReason,
    ExcuseStatus,
    LearnerClass,
    Program,
    TermBadge,
    UpcomingSuspension,
} from '@/types';

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

type Range = { date_from: string; date_to: string };

const props = defineProps<{
    roleLabel: string;
    collegeName: string | null;
    term: TermBadge | null;
    /** The school's date (Asia/Manila), not the browser's. */
    today: string;
    dean: {
        programs: Pick<Program, 'id' | 'code' | 'name'>[];
        sections: {
            id: number;
            program_id: number;
            year_level: number;
            name: string;
        }[];
        section: number | null;
        week: DatedWeek;
        excuses: (Range & {
            id: number;
            learner: string;
            program: string;
            reason: ExcuseReason;
            days_late: number;
            dean_decides: number;
        })[];
        excusesCount: number;
        atRisk: {
            enrollment_id: number;
            learner: string;
            subject: string;
            section: string;
            status: string;
            warning_level: number;
            equivalent: number;
            drop_at: number;
        }[];
        atRiskCounts: { flagged: number; warned: number };
    } | null;
    teacher: {
        week: DatedWeek;
        suspensions: UpcomingSuspension[];
        excuses: (Range & {
            id: number;
            learner: string;
            subject: string;
            section: string;
            reason: ExcuseReason;
        })[];
        excusesCount: number;
    } | null;
    learner: {
        week: DatedWeek;
        suspensions: UpcomingSuspension[];
        excuses: (Range & {
            id: number;
            reason: ExcuseReason;
            status: ExcuseStatus;
        })[];
        attention: (Pick<
            LearnerClass,
            | 'enrollment_id'
            | 'subject'
            | 'section'
            | 'status'
            | 'warning_level'
            | 'drop_reason'
        > & { equivalent: number; drop_at: number })[];
    } | null;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);

const scope = computed(() =>
    props.collegeName
        ? `${props.roleLabel} · ${props.collegeName}`
        : `${props.roleLabel} · Campus-wide`,
);

const termText = computed(() => {
    const term = props.term;

    if (!term) {
        return null;
    }

    switch (term.phase) {
        case 'in_session':
            return `${term.label} · Week ${term.week} of ${term.weeks}`;
        case 'upcoming':
            return `${term.label} · starts ${term.starts_in === 0 ? 'today' : `in ${term.starts_in} day${term.starts_in === 1 ? '' : 's'}`}`;
        default:
            return `${term.label} · ${term.phase === 'closed' ? 'Closed' : 'Ended'}`;
    }
});

// The dean's program, then section, picker.
const program = ref<number | null>(
    props.dean?.sections.find((section) => section.id === props.dean?.section)
        ?.program_id ??
        props.dean?.programs[0]?.id ??
        null,
);
const programSections = computed(
    () =>
        props.dean?.sections.filter(
            (section) => section.program_id === program.value,
        ) ?? [],
);

function showSection(id: number | undefined): void {
    if (id !== undefined) {
        router.get(
            dashboard.url(),
            { section: id, week: props.dean?.week.monday },
            { preserveScroll: true, preserveState: true, only: ['dean'] },
        );
    }
}

function pickProgram(id: number): void {
    program.value = id;
    showSection(programSections.value[0]?.id);
}

function suspensionWhen(item: UpcomingSuspension): string {
    const window =
        item.from && item.until
            ? `, ${formatTime(item.from)}–${formatTime(item.until)}`
            : item.from
              ? `, from ${formatTime(item.from)}`
              : item.until
                ? `, until ${formatTime(item.until)}`
                : '';

    return `${formatDateRange(item.starts_on, item.ends_on)}${window}`;
}
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="`Welcome, ${user.first_name}`"
                :description="scope"
            />
            <Badge
                v-if="termText"
                variant="secondary"
                class="text-sm"
                data-test="term-badge"
                >{{ termText }}</Badge
            >
        </div>

        <!-- Learner: anything wrong comes first. -->
        <section
            v-if="learner && learner.attention.length"
            class="rounded-xl border border-warning/50 bg-warning-soft p-4 text-warning-soft-foreground"
        >
            <p class="mb-2 text-sm font-medium">Needs your attention</p>
            <ul class="space-y-2">
                <li
                    v-for="item in learner.attention"
                    :key="item.enrollment_id"
                    class="flex flex-wrap items-center justify-between gap-2"
                >
                    <span>
                        <Link
                            :href="ClassController.show(item.enrollment_id)"
                            class="font-medium hover:underline"
                            >{{ item.subject.code }}</Link
                        >
                        · {{ item.section }} · {{ item.equivalent }} of
                        {{ item.drop_at }} equivalent absences
                    </span>
                    <StandingBadge :item="item" />
                </li>
            </ul>
        </section>

        <div
            v-if="dean || teacher || learner"
            class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]"
        >
            <!-- Main: the week. -->
            <section class="min-w-0 space-y-3 rounded-xl border p-4">
                <template v-if="dean">
                    <div
                        v-if="dean.sections.length"
                        class="flex flex-wrap gap-2"
                    >
                        <select
                            :value="program"
                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                            aria-label="Program"
                            @change="
                                pickProgram(
                                    Number(
                                        ($event.target as HTMLSelectElement)
                                            .value,
                                    ),
                                )
                            "
                        >
                            <option
                                v-for="option in dean.programs"
                                :key="option.id"
                                :value="option.id"
                            >
                                {{ option.code }}
                            </option>
                        </select>
                        <select
                            :value="dean.section"
                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                            aria-label="Section"
                            @change="
                                showSection(
                                    Number(
                                        ($event.target as HTMLSelectElement)
                                            .value,
                                    ),
                                )
                            "
                        >
                            <option
                                v-for="option in programSections"
                                :key="option.id"
                                :value="option.id"
                            >
                                {{ option.name }} (Year {{ option.year_level }})
                            </option>
                        </select>
                    </div>
                    <p
                        v-else
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        No sections this term yet.
                    </p>
                    <WeekPanel
                        v-if="dean.section"
                        :week="dean.week"
                        viewer="dean"
                        panel="dean"
                        :query="{ section: dean.section }"
                        :today="today"
                    />
                </template>
                <WeekPanel
                    v-else-if="teacher"
                    :week="teacher.week"
                    viewer="teacher"
                    panel="teacher"
                    :today="today"
                />
                <WeekPanel
                    v-else-if="learner"
                    :week="learner.week"
                    viewer="learner"
                    panel="learner"
                    :today="today"
                />
            </section>

            <!-- Sides. -->
            <div class="flex flex-col gap-4">
                <template v-if="dean">
                    <SidePanel
                        title="Excuses waiting"
                        :count="dean.excusesCount"
                        :href="DeanExcuseController.index()"
                        empty="No pending excuses."
                        :is-empty="dean.excuses.length === 0"
                    >
                        <li
                            v-for="excuse in dean.excuses"
                            :key="excuse.id"
                            class="px-4 py-2.5 text-sm"
                        >
                            <Link
                                :href="DeanExcuseController.show(excuse.id)"
                                class="font-medium text-highlight hover:underline"
                                >{{ excuse.learner }}</Link
                            >
                            <span class="text-muted-foreground">
                                · {{ excuse.program }}</span
                            >
                            <div class="text-xs text-muted-foreground">
                                {{
                                    formatDateRange(
                                        excuse.date_from,
                                        excuse.date_to,
                                    )
                                }}
                                · {{ reasonLabels[excuse.reason] }}
                            </div>
                            <div class="mt-1 flex flex-wrap gap-1">
                                <Badge
                                    v-if="excuse.dean_decides"
                                    class="bg-info-soft text-info-soft-foreground"
                                    >You decide {{ excuse.dean_decides }}</Badge
                                >
                                <Badge
                                    v-if="excuse.days_late"
                                    variant="secondary"
                                    >{{ lateLabel(excuse.days_late) }}</Badge
                                >
                            </div>
                        </li>
                    </SidePanel>
                    <SidePanel
                        title="At risk"
                        :count="
                            dean.atRiskCounts.flagged + dean.atRiskCounts.warned
                        "
                        :href="StandingController.index()"
                        empty="No warnings or drop flags this term."
                        :is-empty="dean.atRisk.length === 0"
                    >
                        <li
                            v-for="item in dean.atRisk"
                            :key="item.enrollment_id"
                            class="flex items-start justify-between gap-2 px-4 py-2.5 text-sm"
                        >
                            <div>
                                <span class="font-medium">{{
                                    item.learner
                                }}</span>
                                <div class="text-xs text-muted-foreground">
                                    {{ item.subject }} · {{ item.section }} ·
                                    {{ item.equivalent }} of
                                    {{ item.drop_at }}
                                </div>
                            </div>
                            <Badge
                                :variant="
                                    item.status === 'for_dropping'
                                        ? 'destructive'
                                        : 'secondary'
                                "
                                >{{
                                    item.status === 'for_dropping'
                                        ? 'Flagged'
                                        : `Warning ${item.warning_level}`
                                }}</Badge
                            >
                        </li>
                    </SidePanel>
                </template>

                <template v-else-if="teacher || learner">
                    <SidePanel
                        title="Suspensions coming up"
                        :count="(teacher ?? learner)!.suspensions.length"
                        :href="CalendarController.index()"
                        empty="None of your classes are cancelled in the next 30 days."
                        :is-empty="
                            (teacher ?? learner)!.suspensions.length === 0
                        "
                    >
                        <li
                            v-for="item in (teacher ?? learner)!.suspensions"
                            :key="`${item.title}-${item.starts_on}`"
                            class="px-4 py-2.5 text-sm"
                        >
                            <span class="font-medium">{{ item.title }}</span>
                            <div class="text-xs text-muted-foreground">
                                {{ suspensionWhen(item) }} ·
                                {{ item.classes }} of your class{{
                                    item.classes === 1 ? '' : 'es'
                                }}
                            </div>
                        </li>
                    </SidePanel>

                    <SidePanel
                        v-if="teacher"
                        title="Excuses to decide"
                        :count="teacher.excusesCount"
                        :href="TeacherExcuseController.index()"
                        empty="Nothing to decide."
                        :is-empty="teacher.excuses.length === 0"
                    >
                        <li
                            v-for="excuse in teacher.excuses"
                            :key="excuse.id"
                            class="px-4 py-2.5 text-sm"
                        >
                            <Link
                                :href="ExcuseDecisionController.show(excuse.id)"
                                class="font-medium text-highlight hover:underline"
                                >{{ excuse.learner }}</Link
                            >
                            <div class="text-xs text-muted-foreground">
                                {{ excuse.subject }} · {{ excuse.section }} ·
                                {{
                                    formatDateRange(
                                        excuse.date_from,
                                        excuse.date_to,
                                    )
                                }}
                                · {{ reasonLabels[excuse.reason] }}
                            </div>
                        </li>
                    </SidePanel>

                    <SidePanel
                        v-if="learner"
                        title="Your excuses"
                        :href="ExcuseController.index()"
                        empty="No excuses submitted."
                        :is-empty="learner.excuses.length === 0"
                    >
                        <li
                            v-for="excuse in learner.excuses"
                            :key="excuse.id"
                            class="flex items-start justify-between gap-2 px-4 py-2.5 text-sm"
                        >
                            <div>
                                {{
                                    formatDateRange(
                                        excuse.date_from,
                                        excuse.date_to,
                                    )
                                }}
                                <div class="text-xs text-muted-foreground">
                                    {{ reasonLabels[excuse.reason] }}
                                </div>
                            </div>
                            <Badge variant="secondary">{{
                                statusLabels[excuse.status]
                            }}</Badge>
                        </li>
                    </SidePanel>
                </template>
            </div>
        </div>

        <div
            v-else
            class="rounded-xl border border-sidebar-border/70 p-6 text-sm text-muted-foreground dark:border-sidebar-border"
        >
            Your dashboard is coming soon.
        </div>
    </div>
</template>

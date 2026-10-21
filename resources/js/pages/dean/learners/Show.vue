<script setup lang="ts">
import {
    Head,
    router,
    setLayoutProps,
    useForm,
    usePage,
} from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import LearnerController from '@/actions/App/Http/Controllers/Dean/LearnerController';
import PlacementController from '@/actions/App/Http/Controllers/Dean/PlacementController';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import ScheduleGrid from '@/components/ScheduleGrid.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/native-select';
import type {
    ClassBlock,
    GridBlock,
    PlacementEnrollment,
    PlacementOffering,
    Program,
    TermPhase,
} from '@/types';

const props = defineProps<{
    registration: {
        id: number;
        year_level: number;
        is_irregular: boolean;
        status: 'registered' | 'withdrawn';
        section: { id: number; name: string; capacity: number | null } | null;
        term: { id: number; label: string; phase: TermPhase };
        learner: {
            id: number;
            full_name: string;
            student_number: string;
            program: Pick<Program, 'id' | 'code' | 'name'>;
        };
    };
    enrollments: PlacementEnrollment[];
    offerings: PlacementOffering[];
    sections: {
        id: number;
        name: string;
        capacity: number | null;
        members_count: number;
    }[];
    classes: ClassBlock[];
    canEdit: boolean;
}>();

setLayoutProps({
    breadcrumbs: [
        {
            title: 'Learners',
            href: LearnerController.index({
                query: { term: props.registration.term.id },
            }),
        },
        {
            title: props.registration.learner.full_name,
            href: LearnerController.show(props.registration.id),
        },
    ],
});

const page = usePage();
const errors = computed(() =>
    Object.entries(page.props.errors ?? {})
        .filter(([key]) =>
            /^(section_id|subject_offering_id|batch_id|batches)/.test(key),
        )
        .map(([, message]) => message),
);

/* Section */
const sectionId = ref<number | null>(props.registration.section?.id ?? null);
watch(
    () => props.registration.section,
    (section) => (sectionId.value = section?.id ?? null),
);

function place(): void {
    router.put(
        PlacementController.section.url(props.registration.id),
        { section_id: sectionId.value },
        { preserveScroll: true },
    );
}

/* Subjects */
const active = computed(() =>
    props.enrollments.filter((enrollment) => enrollment.status !== 'dropped'),
);
const dropped = computed(() =>
    props.enrollments.filter((enrollment) => enrollment.status === 'dropped'),
);

const units = computed(() =>
    active.value.reduce(
        (sum, enrollment) =>
            sum +
            Number(enrollment.offering.subject.lecture_units) +
            Number(enrollment.offering.subject.laboratory_units),
        0,
    ),
);

function changeBatch(enrollment: PlacementEnrollment, event: Event): void {
    const value = (event.target as HTMLSelectElement).value;

    router.put(
        PlacementController.batch.url(enrollment.id),
        { batch_id: value === '' ? null : Number(value) },
        {
            preserveScroll: true,
            // Put the select back if the change is refused.
            onError: () => router.reload({ only: ['enrollments'] }),
        },
    );
}

const add = useForm<{
    subject_offering_id: number | null;
    batch_id: number | null;
}>({ subject_offering_id: null, batch_id: null });

const chosenOffering = computed(() =>
    props.offerings.find((offering) => offering.id === add.subject_offering_id),
);
watch(
    () => add.subject_offering_id,
    () => (add.batch_id = null),
);

function enroll(): void {
    add.post(PlacementController.enroll.url(props.registration.id), {
        preserveScroll: true,
        onSuccess: () => add.reset(),
    });
}

/* Week */
const blocks = computed<GridBlock[]>(() => {
    const tones = new Map<string, number>();

    return props.classes.map((item) => {
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
            lines: [item.section, item.room ?? 'Online'],
            tone: tones.get(item.subject) ?? 0,
            group: item.batch ?? '',
        };
    });
});

function unitsOf(offering: PlacementOffering): string {
    return String(
        Number(offering.subject.lecture_units) +
            Number(offering.subject.laboratory_units),
    );
}
</script>

<template>
    <Head :title="registration.learner.full_name" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="registration.learner.full_name"
            :description="`${registration.learner.student_number} · ${registration.learner.program.code} · Year ${registration.year_level} · ${registration.term.label}`"
        />

        <div class="flex flex-wrap gap-2">
            <Badge
                v-if="registration.status === 'withdrawn'"
                variant="secondary"
                >Withdrawn</Badge
            >
            <Badge v-if="registration.is_irregular" variant="outline"
                >Irregular: subjects are set one by one</Badge
            >
            <Badge v-else variant="outline"
                >Regular: takes every subject of their section</Badge
            >
        </div>

        <p
            v-if="!canEdit"
            class="rounded-lg border p-3 text-sm text-muted-foreground"
        >
            {{
                registration.status === 'withdrawn'
                    ? 'The learner has withdrawn, so this is read-only.'
                    : 'This term is closed, so this is read-only.'
            }}
        </p>

        <div
            v-if="errors.length"
            class="rounded-lg border border-destructive/50 p-3 text-sm text-destructive"
            role="alert"
        >
            <p v-for="error in errors" :key="error">{{ error }}</p>
        </div>

        <section class="space-y-3">
            <Heading
                variant="small"
                title="Section"
                :description="
                    registration.is_irregular
                        ? 'Moving an irregular learner keeps their subjects.'
                        : 'Moving a regular learner swaps the old section\'s subjects for the new one\'s (subjects with attendance stay on record as dropped).'
                "
            />
            <div class="flex flex-wrap items-center gap-3">
                <NativeSelect
                    v-model="sectionId"
                    aria-label="Section"
                    :disabled="!canEdit"
                >
                    <option :value="null" disabled>No section</option>
                    <option
                        v-for="section in sections"
                        :key="section.id"
                        :value="section.id"
                    >
                        {{ registration.learner.program.code }}
                        {{ section.name }} ({{ section.members_count
                        }}{{
                            section.capacity ? ` of ${section.capacity}` : ''
                        }})
                    </option>
                </NativeSelect>
                <Button
                    v-if="canEdit"
                    :disabled="
                        sectionId === null ||
                        sectionId === registration.section?.id
                    "
                    @click="place"
                    >{{ registration.section ? 'Move' : 'Place' }}</Button
                >
                <span
                    v-if="sections.length === 0"
                    class="text-sm text-muted-foreground"
                    >The program has no year
                    {{ registration.year_level }} sections this term.</span
                >
            </div>
        </section>

        <section class="space-y-3">
            <Heading
                variant="small"
                title="Subjects"
                :description="`${active.length} subject${active.length === 1 ? '' : 's'} · ${units} units`"
            />
            <div v-table-scroll class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Subject</th>
                            <th class="px-4 py-2 font-medium">Section</th>
                            <th class="px-4 py-2 font-medium">Batch</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Units
                            </th>
                            <th class="table-actions px-4 py-2">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="enrollment in [...active, ...dropped]"
                            :key="enrollment.id"
                            class="border-t"
                            :class="{
                                'text-muted-foreground':
                                    enrollment.status === 'dropped',
                            }"
                        >
                            <td class="px-4 py-2">
                                <span class="font-medium">{{
                                    enrollment.offering.subject.code
                                }}</span>
                                {{ enrollment.offering.subject.title }}
                                <Badge
                                    v-if="enrollment.status === 'dropped'"
                                    variant="secondary"
                                    class="ml-2"
                                    >Dropped</Badge
                                >
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap">
                                {{ enrollment.offering.section }}
                            </td>
                            <td class="px-4 py-2">
                                <NativeSelect
                                    size="sm"
                                    v-if="
                                        enrollment.offering.batches.length &&
                                        enrollment.status !== 'dropped'
                                    "
                                    :value="enrollment.batch_id ?? ''"
                                    :disabled="!canEdit"
                                    :aria-label="`Batch in ${enrollment.offering.subject.code}`"
                                    @change="changeBatch(enrollment, $event)"
                                >
                                    <option value="">—</option>
                                    <option
                                        v-for="batch in enrollment.offering
                                            .batches"
                                        :key="batch.id"
                                        :value="batch.id"
                                    >
                                        {{ batch.name }}
                                    </option>
                                </NativeSelect>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ unitsOf(enrollment.offering) }}
                            </td>
                            <td class="table-actions px-4 py-2 text-right">
                                <ConfirmActionDialog
                                    v-if="
                                        canEdit &&
                                        enrollment.status !== 'dropped'
                                    "
                                    :title="`Remove ${enrollment.offering.subject.code}?`"
                                    :description="
                                        (enrollment.attendance_count
                                            ? 'It has attendance, so it stays on record as dropped.'
                                            : 'Nothing has been recorded for it, so it is removed.') +
                                        (registration.is_irregular
                                            ? ''
                                            : ' The learner becomes irregular.')
                                    "
                                    confirm-label="Remove"
                                    destructive
                                    method="delete"
                                    :url="
                                        PlacementController.remove.url(
                                            enrollment.id,
                                        )
                                    "
                                >
                                    <Button variant="ghost" size="sm"
                                        >Remove</Button
                                    >
                                </ConfirmActionDialog>
                            </td>
                        </tr>
                        <tr v-if="enrollments.length === 0">
                            <td
                                colspan="5"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                No subjects yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <form
                v-if="canEdit"
                class="flex flex-wrap items-center gap-3"
                @submit.prevent="enroll"
            >
                <NativeSelect
                    v-model="add.subject_offering_id"
                    class="max-w-md"
                    aria-label="Subject to add"
                >
                    <option :value="null" disabled>Add a subject…</option>
                    <option
                        v-for="offering in offerings"
                        :key="offering.id"
                        :value="offering.id"
                    >
                        {{ offering.subject.code }}
                        {{ offering.subject.title }} · {{ offering.section }}
                    </option>
                </NativeSelect>
                <NativeSelect
                    v-if="chosenOffering?.batches.length"
                    v-model="add.batch_id"
                    aria-label="Batch"
                >
                    <option :value="null">No batch</option>
                    <option
                        v-for="batch in chosenOffering.batches"
                        :key="batch.id"
                        :value="batch.id"
                    >
                        {{ batch.name }}
                    </option>
                </NativeSelect>
                <Button
                    :disabled="
                        add.subject_offering_id === null || add.processing
                    "
                    >Add</Button
                >
                <span
                    v-if="!registration.is_irregular"
                    class="text-sm text-muted-foreground"
                    >Adding or removing a subject makes the learner
                    irregular.</span
                >
            </form>
        </section>

        <section class="space-y-3">
            <ScheduleGrid :blocks="blocks">
                <template #heading>
                    <Heading
                        variant="small"
                        title="Week"
                        description="Whole-class meetings plus those of the learner's batches"
                    />
                </template>
            </ScheduleGrid>
        </section>
    </div>
</template>

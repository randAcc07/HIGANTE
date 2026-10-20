<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { CalendarClock, Plus, UserRound } from '@lucide/vue';
import BatchController from '@/actions/App/Http/Controllers/Dean/BatchController';
import OfferingController from '@/actions/App/Http/Controllers/Dean/OfferingController';
import SectionController from '@/actions/App/Http/Controllers/Dean/SectionController';
import TimetableController from '@/actions/App/Http/Controllers/Dean/TimetableController';
import ConfirmDeleteDialog from '@/components/ConfirmDeleteDialog.vue';
import BatchFormDialog from '@/components/dean/BatchFormDialog.vue';
import OfferingFormDialog from '@/components/dean/OfferingFormDialog.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type {
    Offering,
    Program,
    Subject,
    TeacherOption,
    TermPhase,
} from '@/types';

const props = defineProps<{
    section: {
        id: number;
        name: string;
        year_level: number;
        capacity: number | null;
        program: Pick<Program, 'id' | 'code' | 'name'>;
        term: { id: number; label: string; phase: TermPhase };
        registered: number;
    };
    offerings: Offering[];
    subjects: Pick<Subject, 'id' | 'code' | 'title'>[];
    teachers: TeacherOption[];
    canEdit: boolean;
}>();

setLayoutProps({
    breadcrumbs: [
        {
            title: 'Sections',
            href: SectionController.index({
                query: { term: props.section.term.id },
            }),
        },
        {
            title: `${props.section.program.code} ${props.section.name}`,
            href: SectionController.show(props.section.id),
        },
    ],
});

function units(offering: Offering): string {
    const lecture = Number(offering.subject.lecture_units);
    const laboratory = Number(offering.subject.laboratory_units);

    return [
        lecture ? `${lecture} lec` : '',
        laboratory ? `${laboratory} lab` : '',
    ]
        .filter(Boolean)
        .join(' + ');
}

function classesPerWeek(offering: Offering): string {
    const count = offering.schedules_count ?? 0;

    return count === 0
        ? 'Not scheduled'
        : `${count} class${count === 1 ? '' : 'es'}/week`;
}

function nextBatchName(offering: Offering): string {
    return `${props.section.name}${String.fromCharCode(65 + offering.batches.length)}`;
}
</script>

<template>
    <Head :title="`${section.program.code} ${section.name}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="`${section.program.code} ${section.name}`"
                :description="`${section.program.name} · Year ${section.year_level} · ${section.term.label} · ${section.registered} registered${section.capacity ? ` of ${section.capacity}` : ''}`"
            />
            <Button variant="outline" as-child>
                <Link
                    :href="
                        TimetableController.index({
                            query: {
                                term: section.term.id,
                                view: 'section',
                                target: section.id,
                            },
                        })
                    "
                >
                    <CalendarClock class="h-4 w-4" /> Open timetable
                </Link>
            </Button>
        </div>

        <p
            v-if="!canEdit"
            class="rounded-lg border p-3 text-sm text-muted-foreground"
        >
            This term is closed, so the section is read-only.
        </p>

        <section class="space-y-3">
            <div class="flex items-center justify-between gap-4">
                <Heading
                    variant="small"
                    title="Subjects"
                    description="Each with a default teacher; batches split its learners"
                />
                <OfferingFormDialog
                    v-if="canEdit"
                    :section-id="section.id"
                    :teachers="teachers"
                    :subjects="subjects"
                >
                    <Button size="sm" :disabled="subjects.length === 0"
                        ><Plus class="h-4 w-4" /> Add subject</Button
                    >
                </OfferingFormDialog>
            </div>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Subject</th>
                            <th class="px-4 py-2 font-medium">Units</th>
                            <th class="px-4 py-2 font-medium">
                                Default teacher
                            </th>
                            <th class="px-4 py-2 font-medium">Batches</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Enrolled
                            </th>
                            <th class="px-4 py-2">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="offering in offerings"
                            :key="offering.id"
                            class="border-t align-top"
                        >
                            <td class="px-4 py-2">
                                <div class="font-medium">
                                    {{ offering.subject.code }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ offering.subject.title }}
                                </div>
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap">
                                {{ units(offering) }}
                                <div class="text-xs text-muted-foreground">
                                    {{ classesPerWeek(offering) }}
                                </div>
                            </td>
                            <td class="px-4 py-2">
                                <span v-if="offering.teacher">{{
                                    offering.teacher.user.full_name
                                }}</span>
                                <Badge v-else variant="outline">TBA</Badge>
                            </td>
                            <td class="px-4 py-2">
                                <div class="flex flex-wrap items-center gap-1">
                                    <template
                                        v-for="batch in offering.batches"
                                        :key="batch.id"
                                    >
                                        <BatchFormDialog
                                            v-if="canEdit"
                                            :offering-id="offering.id"
                                            :subject-code="
                                                offering.subject.code
                                            "
                                            :batch="batch"
                                        >
                                            <button
                                                type="button"
                                                class="rounded-md border px-2 py-0.5 text-xs hover:bg-muted"
                                            >
                                                {{ batch.name }} ·
                                                {{ batch.enrollments_count }}
                                            </button>
                                        </BatchFormDialog>
                                        <span
                                            v-else
                                            class="rounded-md border px-2 py-0.5 text-xs"
                                            >{{ batch.name }} ·
                                            {{ batch.enrollments_count }}</span
                                        >
                                        <ConfirmDeleteDialog
                                            v-if="
                                                canEdit &&
                                                !batch.schedules_count &&
                                                !batch.enrollments_count
                                            "
                                            :title="`Delete batch ${batch.name}?`"
                                            description="Only a batch with no classes and no learners can be deleted."
                                            :url="
                                                BatchController.destroy.url(
                                                    batch.id,
                                                )
                                            "
                                        />
                                    </template>
                                    <BatchFormDialog
                                        v-if="canEdit"
                                        :offering-id="offering.id"
                                        :subject-code="offering.subject.code"
                                        :suggested-name="
                                            nextBatchName(offering)
                                        "
                                    >
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="h-6 px-2 text-xs"
                                            ><Plus class="h-3 w-3" />
                                            Batch</Button
                                        >
                                    </BatchFormDialog>
                                </div>
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ offering.enrollments_count }}
                            </td>
                            <td class="px-4 py-2">
                                <div
                                    v-if="canEdit"
                                    class="flex justify-end gap-1"
                                >
                                    <OfferingFormDialog
                                        :section-id="section.id"
                                        :teachers="teachers"
                                        :offering="offering"
                                    >
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            title="Change teacher"
                                        >
                                            <UserRound class="h-4 w-4" />
                                            <span class="sr-only"
                                                >Change teacher</span
                                            >
                                        </Button>
                                    </OfferingFormDialog>
                                    <ConfirmDeleteDialog
                                        :title="`Remove ${offering.subject.code}?`"
                                        description="Its batches, classes and enrollments go with it. A subject with recorded attendance stays."
                                        :url="
                                            OfferingController.destroy.url(
                                                offering.id,
                                            )
                                        "
                                    />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="offerings.length === 0">
                            <td
                                colspan="6"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                No subjects yet. Add the section's subjects,
                                then schedule their classes below.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>

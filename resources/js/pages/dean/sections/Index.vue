<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CalendarClock,
    Copy,
    ListPlus,
    Pencil,
    Plus,
    UsersRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import SectionController from '@/actions/App/Http/Controllers/Dean/SectionController';
import SectionCopyController from '@/actions/App/Http/Controllers/Dean/SectionCopyController';
import TimetableController from '@/actions/App/Http/Controllers/Dean/TimetableController';
import ConfirmDeleteDialog from '@/components/ConfirmDeleteDialog.vue';
import CopySectionsDialog from '@/components/dean/CopySectionsDialog.vue';
import SectionFormDialog from '@/components/dean/SectionFormDialog.vue';
import Heading from '@/components/Heading.vue';
import NextTermButton from '@/components/NextTermButton.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/native-select';
import type { Program, TermPhase } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Sections', href: SectionController.index() }],
    },
});

type SectionRow = {
    id: number;
    program_id: number;
    year_level: number;
    name: string;
    capacity: number | null;
    program: Pick<Program, 'id' | 'code'>;
    term_registrations_count: number;
    subject_offerings_count: number;
};

type TermOption = { id: number; label: string; phase: TermPhase };

type CopyOffer = {
    from: { id: number; label: string };
    shiftsUp: boolean;
    sections: {
        id: number;
        program_id: number;
        program: string;
        from: string;
        year_level: number;
        name: string;
        capacity: number | null;
        ticked: boolean;
    }[];
};

const props = defineProps<{
    terms: TermOption[];
    term: TermOption | null;
    programs: Pick<Program, 'id' | 'code' | 'name'>[];
    sections: SectionRow[];
    canEdit: boolean;
    /** The next term, while the page shows another: a shortcut to it. */
    nextTerm: { id: number; label: string } | null;
    /** The latest earlier term's sections, to copy; null when there are none. */
    copy: CopyOffer | null;
    /** Curriculum subjects the term's sections lack, in all. */
    curriculumMissing: number;
}>();

const filling = ref(false);

/** Whether any of the earlier term's blocks isn't in this term yet. */
const copyable = computed(
    () =>
        props.copy?.sections.some(
            (proposal) =>
                !props.sections.some(
                    (section) =>
                        section.program_id === proposal.program_id &&
                        section.name.toLowerCase() ===
                            proposal.name.toLowerCase(),
                ),
        ) ?? false,
);

function fillFromCurriculum(): void {
    if (!props.term) {
        return;
    }

    filling.value = true;
    router.post(
        SectionCopyController.fill.url(props.term.id),
        {},
        { preserveScroll: true, onFinish: () => (filling.value = false) },
    );
}

const phaseLabels: Record<TermPhase, string> = {
    upcoming: 'Upcoming',
    in_session: 'In session',
    ended: 'Ended',
    closed: 'Closed',
};

function showTerm(id: number): void {
    router.get(SectionController.index.url(), { term: id });
}
</script>

<template>
    <Head title="Sections" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Sections"
                :description="
                    term
                        ? `${term.label} · ${phaseLabels[term.phase]}`
                        : 'No terms yet'
                "
            />
            <div class="flex gap-2">
                <NativeSelect
                    v-if="terms.length > 1"
                    :value="term?.id"
                    aria-label="Term"
                    @change="
                        showTerm(
                            Number(($event.target as HTMLSelectElement).value),
                        )
                    "
                >
                    <option
                        v-for="option in terms"
                        :key="option.id"
                        :value="option.id"
                    >
                        {{ option.label }} ({{ phaseLabels[option.phase] }})
                    </option>
                </NativeSelect>
                <NextTermButton
                    v-if="nextTerm"
                    :term="nextTerm"
                    @pick="showTerm"
                />
                <Button
                    v-if="term && canEdit && curriculumMissing"
                    variant="outline"
                    :disabled="filling"
                    @click="fillFromCurriculum"
                    ><ListPlus class="h-4 w-4" /> Add
                    {{ curriculumMissing }} curriculum subject{{
                        curriculumMissing === 1 ? '' : 's'
                    }}</Button
                >
                <CopySectionsDialog
                    v-if="term && canEdit && copy && copyable"
                    :key="`${term.id}-${sections.length}`"
                    :term-id="term.id"
                    :term-label="term.label"
                    :from="copy.from"
                    :shifts-up="copy.shiftsUp"
                    :proposals="copy.sections"
                    :existing="sections"
                >
                    <Button variant="outline"
                        ><Copy class="h-4 w-4" /> Copy sections</Button
                    >
                </CopySectionsDialog>
                <SectionFormDialog
                    v-if="term && canEdit"
                    :term-id="term.id"
                    :programs="programs"
                >
                    <Button><Plus class="h-4 w-4" /> Add section</Button>
                </SectionFormDialog>
            </div>
        </div>

        <p
            v-if="term && !canEdit"
            class="rounded-lg border p-3 text-sm text-muted-foreground"
        >
            This term is closed, so its sections are read-only.
        </p>

        <div v-table-scroll class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Section</th>
                        <th class="px-4 py-2 font-medium">Year</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Registered
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Capacity
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Subjects
                        </th>
                        <th class="table-actions px-4 py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="section in sections"
                        :key="section.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2 font-medium">
                            {{ section.program.code }} {{ section.name }}
                        </td>
                        <td class="px-4 py-2">{{ section.year_level }}</td>
                        <td class="px-4 py-2 text-right">
                            {{ section.term_registrations_count }}
                            <Badge
                                v-if="
                                    section.capacity !== null &&
                                    section.term_registrations_count >
                                        section.capacity
                                "
                                variant="destructive"
                                class="ml-1"
                                >Over</Badge
                            >
                        </td>
                        <td class="px-4 py-2 text-right">
                            {{ section.capacity ?? '—' }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            {{ section.subject_offerings_count }}
                        </td>
                        <td class="table-actions px-4 py-2">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    as-child
                                    title="Subjects and batches"
                                >
                                    <Link
                                        :href="
                                            SectionController.show(section.id)
                                        "
                                    >
                                        <Pencil class="h-4 w-4" />
                                        <span class="sr-only"
                                            >Subjects and batches</span
                                        >
                                    </Link>
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    as-child
                                    title="Timetable"
                                >
                                    <Link
                                        :href="
                                            TimetableController.index({
                                                query: {
                                                    term: term?.id,
                                                    view: 'section',
                                                    target: section.id,
                                                },
                                            })
                                        "
                                    >
                                        <CalendarClock class="h-4 w-4" />
                                        <span class="sr-only">Timetable</span>
                                    </Link>
                                </Button>
                                <SectionFormDialog
                                    v-if="canEdit && term"
                                    :term-id="term.id"
                                    :programs="programs"
                                    :section="section"
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        title="Name and capacity"
                                    >
                                        <UsersRound class="h-4 w-4" />
                                        <span class="sr-only"
                                            >Name and capacity</span
                                        >
                                    </Button>
                                </SectionFormDialog>
                                <ConfirmDeleteDialog
                                    v-if="canEdit"
                                    :title="`Delete ${section.program.code} ${section.name}?`"
                                    description="Its subjects, batches and classes go with it. Only a section with no registered or enrolled learners can be deleted."
                                    :url="
                                        SectionController.destroy.url(
                                            section.id,
                                        )
                                    "
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="sections.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No sections for this term yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

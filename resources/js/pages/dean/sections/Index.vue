<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import SectionController from '@/actions/App/Http/Controllers/Dean/SectionController';
import ConfirmDeleteDialog from '@/components/ConfirmDeleteDialog.vue';
import SectionFormDialog from '@/components/dean/SectionFormDialog.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

defineProps<{
    terms: TermOption[];
    term: TermOption | null;
    programs: Pick<Program, 'id' | 'code' | 'name'>[];
    sections: SectionRow[];
    canEdit: boolean;
}>();

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
                <select
                    v-if="terms.length > 1"
                    :value="term?.id"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
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
                </select>
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

        <div class="overflow-x-auto rounded-lg border">
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
                        <th class="px-4 py-2">
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
                            <Link
                                :href="SectionController.show(section.id)"
                                class="underline-offset-4 hover:underline"
                            >
                                {{ section.program.code }} {{ section.name }}
                            </Link>
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
                        <td class="px-4 py-2">
                            <div
                                v-if="canEdit && term"
                                class="flex justify-end gap-1"
                            >
                                <SectionFormDialog
                                    :term-id="term.id"
                                    :programs="programs"
                                    :section="section"
                                >
                                    <Button variant="ghost" size="sm">
                                        <Pencil class="h-4 w-4" />
                                        <span class="sr-only">Edit</span>
                                    </Button>
                                </SectionFormDialog>
                                <ConfirmDeleteDialog
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

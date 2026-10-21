<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { MoveRight, Plus } from '@lucide/vue';
import { computed } from 'vue';
import CurriculumController from '@/actions/App/Http/Controllers/Dean/CurriculumController';
import ConfirmDeleteDialog from '@/components/ConfirmDeleteDialog.vue';
import CurriculumFormDialog from '@/components/dean/CurriculumFormDialog.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/native-select';
import type { CurriculumLine, Program, Semester, Subject } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Curriculum', href: CurriculumController.index() },
        ],
    },
});

const props = defineProps<{
    programs: Pick<Program, 'id' | 'code' | 'name'>[];
    program: Pick<Program, 'id' | 'code' | 'name'> | null;
    lines: CurriculumLine[];
    subjects: Pick<Subject, 'id' | 'code' | 'title'>[];
    semesters: { value: Semester; label: string }[];
}>();

type Block = {
    key: string;
    year: number;
    semester: Semester;
    label: string;
    lines: CurriculumLine[];
    units: number;
};

/** The curriculum by year level, then semester (lines arrive sorted). */
const blocks = computed<Block[]>(() => {
    const byKey = new Map<string, Block>();

    for (const line of props.lines) {
        const key = `${line.year_level}-${line.semester}`;
        const block = byKey.get(key) ?? {
            key,
            year: line.year_level,
            semester: line.semester,
            label: `Year ${line.year_level} · ${props.semesters.find((option) => option.value === line.semester)?.label}`,
            lines: [],
            units: 0,
        };
        block.lines.push(line);
        block.units += totalUnits(line);
        byKey.set(key, block);
    }

    return [...byKey.values()];
});

function totalUnits(line: CurriculumLine): number {
    return (
        Number(line.subject.lecture_units) +
        Number(line.subject.laboratory_units)
    );
}

function units(line: CurriculumLine): string {
    const lecture = Number(line.subject.lecture_units);
    const laboratory = Number(line.subject.laboratory_units);

    return [
        lecture ? `${lecture} lec` : '',
        laboratory ? `${laboratory} lab` : '',
    ]
        .filter(Boolean)
        .join(' + ');
}

function showProgram(id: number): void {
    router.get(CurriculumController.index.url(), { program: id });
}
</script>

<template>
    <Head title="Curriculum" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Curriculum"
                :description="
                    program
                        ? `${program.name} · new sections get their year and semester's subjects`
                        : 'Your college has no programs yet'
                "
            />
            <div v-if="program" class="flex gap-2">
                <NativeSelect
                    v-if="programs.length > 1"
                    :value="program.id"
                    aria-label="Program"
                    @change="
                        showProgram(
                            Number(($event.target as HTMLSelectElement).value),
                        )
                    "
                >
                    <option
                        v-for="option in programs"
                        :key="option.id"
                        :value="option.id"
                    >
                        {{ option.code }}
                    </option>
                </NativeSelect>
                <CurriculumFormDialog
                    :program-id="program.id"
                    :semesters="semesters"
                    :subjects="subjects"
                >
                    <Button :disabled="subjects.length === 0"
                        ><Plus class="h-4 w-4" /> Add subjects</Button
                    >
                </CurriculumFormDialog>
            </div>
        </div>

        <p
            v-if="program && blocks.length === 0"
            class="rounded-lg border p-6 text-center text-sm text-muted-foreground"
        >
            {{ program.code }} has no curriculum yet. Add each year and
            semester's subjects, and new sections get them automatically.
        </p>

        <section v-for="block in blocks" :key="block.key" class="space-y-2">
            <div class="flex items-center justify-between gap-4">
                <h2 class="font-medium">
                    {{ block.label }}
                    <span class="text-sm font-normal text-muted-foreground">
                        · {{ block.lines.length }} subject{{
                            block.lines.length === 1 ? '' : 's'
                        }}, {{ block.units }} units
                    </span>
                </h2>
                <CurriculumFormDialog
                    v-if="program"
                    :program-id="program.id"
                    :semesters="semesters"
                    :subjects="subjects"
                    :year-level="block.year"
                    :semester="block.semester"
                >
                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="subjects.length === 0"
                        :aria-label="`Add subjects to ${block.label}`"
                        ><Plus class="h-4 w-4" /> Add</Button
                    >
                </CurriculumFormDialog>
            </div>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="w-32 px-4 py-2 font-medium">Code</th>
                            <th class="px-4 py-2 font-medium">Title</th>
                            <th class="w-40 px-4 py-2 font-medium">Units</th>
                            <th class="w-24 px-4 py-2">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="line in block.lines"
                            :key="line.id"
                            class="border-t"
                        >
                            <td class="px-4 py-2 font-medium whitespace-nowrap">
                                {{ line.subject.code }}
                            </td>
                            <td class="px-4 py-2">{{ line.subject.title }}</td>
                            <td class="px-4 py-2 whitespace-nowrap">
                                {{ units(line) }}
                            </td>
                            <td class="px-4 py-2">
                                <div
                                    v-if="program"
                                    class="flex justify-end gap-1"
                                >
                                    <CurriculumFormDialog
                                        :program-id="program.id"
                                        :semesters="semesters"
                                        :line="line"
                                    >
                                        <Button variant="ghost" size="sm">
                                            <MoveRight class="h-4 w-4" />
                                            <span class="sr-only"
                                                >Move
                                                {{ line.subject.code }}</span
                                            >
                                        </Button>
                                    </CurriculumFormDialog>
                                    <ConfirmDeleteDialog
                                        :title="`Remove ${line.subject.code} from the curriculum?`"
                                        description="Sections that already have it keep it; only new sections are affected."
                                        :url="
                                            CurriculumController.destroy.url(
                                                line.id,
                                            )
                                        "
                                    />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>

<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import CollegeController from '@/actions/App/Http/Controllers/Admin/CollegeController';
import ProgramController from '@/actions/App/Http/Controllers/Admin/ProgramController';
import SubjectController from '@/actions/App/Http/Controllers/Admin/SubjectController';
import CollegeFormDialog from '@/components/admin/CollegeFormDialog.vue';
import ProgramFormDialog from '@/components/admin/ProgramFormDialog.vue';
import ConfirmDeleteDialog from '@/components/ConfirmDeleteDialog.vue';
import Heading from '@/components/Heading.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import type { College, Program } from '@/types';

const props = defineProps<{
    college: College;
    programs: Program[];
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Colleges', href: CollegeController.index() },
        {
            title: props.college.code,
            href: CollegeController.show(props.college.id),
        },
    ],
});
</script>

<template>
    <Head :title="college.name" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading :title="college.name" :description="college.code" />
            <CollegeFormDialog :college="college">
                <Button variant="outline"
                    ><Pencil class="h-4 w-4" /> Edit</Button
                >
            </CollegeFormDialog>
        </div>

        <dl class="grid grid-cols-3 gap-4 text-sm">
            <div class="rounded-lg border p-4">
                <dt class="text-muted-foreground">Subjects</dt>
                <dd class="text-2xl font-semibold">
                    {{ college.subjects_count }}
                </dd>
                <TextLink
                    :href="
                        SubjectController.index({
                            query: { college: props.college.id },
                        })
                    "
                    class="text-xs"
                    >View subjects</TextLink
                >
            </div>
            <div class="rounded-lg border p-4">
                <dt class="text-muted-foreground">Staff</dt>
                <dd class="text-2xl font-semibold">
                    {{ college.staff_count }}
                </dd>
            </div>
            <div class="rounded-lg border p-4">
                <dt class="text-muted-foreground">Learners</dt>
                <dd class="text-2xl font-semibold">
                    {{ college.learner_count }}
                </dd>
            </div>
        </dl>

        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                title="Programs"
                description="Degree programs offered by this college"
            />
            <ProgramFormDialog :college-id="college.id">
                <Button size="sm"><Plus class="h-4 w-4" /> Add program</Button>
            </ProgramFormDialog>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Code</th>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Learners
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Sections
                        </th>
                        <th class="px-4 py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="program in programs"
                        :key="program.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2 font-medium">
                            {{ program.code }}
                        </td>
                        <td class="px-4 py-2">{{ program.name }}</td>
                        <td class="px-4 py-2 text-right">
                            {{ program.learners_count }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            {{ program.sections_count }}
                        </td>
                        <td class="px-4 py-2">
                            <div class="flex justify-end gap-1">
                                <ProgramFormDialog
                                    :college-id="college.id"
                                    :program="program"
                                >
                                    <Button variant="ghost" size="sm">
                                        <Pencil class="h-4 w-4" />
                                        <span class="sr-only">Edit</span>
                                    </Button>
                                </ProgramFormDialog>
                                <ConfirmDeleteDialog
                                    :title="`Delete ${program.code}?`"
                                    description="Only a program with no learners or sections can be deleted."
                                    :url="
                                        ProgramController.destroy.url(
                                            program.id,
                                        )
                                    "
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="programs.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No programs yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

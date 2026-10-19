<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import CollegeController from '@/actions/App/Http/Controllers/Admin/CollegeController';
import CollegeFormDialog from '@/components/admin/CollegeFormDialog.vue';
import ConfirmDeleteDialog from '@/components/ConfirmDeleteDialog.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import type { College } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Colleges', href: CollegeController.index() }],
    },
});

defineProps<{
    colleges: College[];
}>();
</script>

<template>
    <Head title="Colleges" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Colleges"
                description="Each college has its own programs, subjects, staff and learners"
            />
            <CollegeFormDialog>
                <Button><Plus class="h-4 w-4" /> Add college</Button>
            </CollegeFormDialog>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Code</th>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Programs
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Subjects
                        </th>
                        <th class="px-4 py-2 text-right font-medium">Staff</th>
                        <th class="px-4 py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="college in colleges"
                        :key="college.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2 font-medium">
                            <Link
                                :href="CollegeController.show(college.id)"
                                class="underline-offset-4 hover:underline"
                                >{{ college.code }}</Link
                            >
                        </td>
                        <td class="px-4 py-2">{{ college.name }}</td>
                        <td class="px-4 py-2 text-right">
                            {{ college.programs_count }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            {{ college.subjects_count }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            {{ college.staff_count }}
                        </td>
                        <td class="px-4 py-2">
                            <div class="flex justify-end gap-1">
                                <CollegeFormDialog :college="college">
                                    <Button variant="ghost" size="sm">
                                        <Pencil class="h-4 w-4" />
                                        <span class="sr-only">Edit</span>
                                    </Button>
                                </CollegeFormDialog>
                                <ConfirmDeleteDialog
                                    :title="`Delete ${college.code}?`"
                                    description="Only a college with no programs, subjects, accounts or calendar events can be deleted."
                                    :url="
                                        CollegeController.destroy.url(
                                            college.id,
                                        )
                                    "
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="colleges.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No colleges yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

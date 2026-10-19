<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import SubjectController from '@/actions/App/Http/Controllers/Admin/SubjectController';
import SubjectFormDialog from '@/components/admin/SubjectFormDialog.vue';
import ConfirmDeleteDialog from '@/components/ConfirmDeleteDialog.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/composables/useFilters';
import type { College, Paginated, Subject } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Subjects', href: SubjectController.index() }],
    },
});

const props = defineProps<{
    subjects: Paginated<Subject>;
    colleges: Pick<College, 'id' | 'code' | 'name'>[];
    filters: { college: number | null; search: string };
}>();

const filters = useFilters(SubjectController.index.url(), {
    college: props.filters.college,
    search: props.filters.search,
});

function units(subject: Subject): string {
    const lecture = Number(subject.lecture_units);
    const laboratory = Number(subject.laboratory_units);

    return [
        lecture ? `${lecture} lec` : '',
        laboratory ? `${laboratory} lab` : '',
    ]
        .filter(Boolean)
        .join(' + ');
}
</script>

<template>
    <Head title="Subjects" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Subjects"
                description="Each college owns its subjects and offers them to its own sections"
            />
            <SubjectFormDialog
                :colleges="colleges"
                :default-college-id="filters.college"
            >
                <Button><Plus class="h-4 w-4" /> Add subject</Button>
            </SubjectFormDialog>
        </div>

        <div class="flex flex-wrap gap-3">
            <select
                v-model="filters.college"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                aria-label="College"
            >
                <option :value="null">All colleges</option>
                <option
                    v-for="college in colleges"
                    :key="college.id"
                    :value="college.id"
                >
                    {{ college.code }}
                </option>
            </select>
            <Input
                v-model="filters.search"
                class="max-w-xs"
                placeholder="Search code or title"
                aria-label="Search subjects"
            />
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">College</th>
                        <th class="px-4 py-2 font-medium">Code</th>
                        <th class="px-4 py-2 font-medium">Title</th>
                        <th class="px-4 py-2 font-medium">Units</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Offerings
                        </th>
                        <th class="px-4 py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="subject in subjects.data"
                        :key="subject.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ subject.college?.code }}
                        </td>
                        <td class="px-4 py-2 font-medium whitespace-nowrap">
                            {{ subject.code }}
                        </td>
                        <td class="px-4 py-2">{{ subject.title }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{ units(subject) }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            {{ subject.offerings_count }}
                        </td>
                        <td class="px-4 py-2">
                            <div class="flex justify-end gap-1">
                                <SubjectFormDialog
                                    :colleges="colleges"
                                    :subject="subject"
                                >
                                    <Button variant="ghost" size="sm">
                                        <Pencil class="h-4 w-4" />
                                        <span class="sr-only">Edit</span>
                                    </Button>
                                </SubjectFormDialog>
                                <ConfirmDeleteDialog
                                    :title="`Delete ${subject.code}?`"
                                    description="Only a subject that is not offered to any section can be deleted."
                                    :url="
                                        SubjectController.destroy.url(
                                            subject.id,
                                        )
                                    "
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="subjects.data.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No subjects match.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="subjects" />
    </div>
</template>

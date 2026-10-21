<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { FileSpreadsheet, KeyRound, Pencil, Plus } from '@lucide/vue';
import { computed } from 'vue';
import LearnerController from '@/actions/App/Http/Controllers/Registrar/LearnerController';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import LearnerFormDialog from '@/components/registrar/LearnerFormDialog.vue';
import LearnerImportDialog from '@/components/registrar/LearnerImportDialog.vue';
import SetupLinkDialog from '@/components/SetupLinkDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/composables/useFilters';
import type {
    College,
    LearnerRecord,
    LearnerStatus,
    Paginated,
    Program,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Learners', href: LearnerController.index() }],
    },
});

const props = defineProps<{
    learners: Paginated<LearnerRecord>;
    colleges: (Pick<College, 'id' | 'code' | 'name'> & {
        programs: Pick<Program, 'id' | 'college_id' | 'code' | 'name'>[];
    })[];
    registrationTerms: { id: number; label: string }[];
    defaultRegistrationTerm: number | null;
    filters: {
        search: string | null;
        college: number | null;
        program: number | null;
        year: number | null;
        status: string | null;
    };
}>();

const filters = useFilters(LearnerController.index.url(), {
    search: props.filters.search ?? '',
    college: props.filters.college,
    program: props.filters.program,
    year: props.filters.year,
    status: props.filters.status,
});

const programs = computed(() =>
    props.colleges
        .filter(
            (college) =>
                filters.college === null || college.id === filters.college,
        )
        .flatMap((college) => college.programs),
);

const statusLabels: Record<LearnerStatus, string> = {
    active: 'Active',
    inactive: 'On leave',
    graduated: 'Graduated',
    dropped_out: 'Dropped out',
};
</script>

<template>
    <Head title="Learners" />
    <SetupLinkDialog />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Learners"
                description="Admission and student records"
            />
            <div class="flex flex-wrap gap-2">
                <LearnerImportDialog :registration-terms="registrationTerms">
                    <Button variant="outline"
                        ><FileSpreadsheet class="h-4 w-4" /> Import from a
                        sheet</Button
                    >
                </LearnerImportDialog>
                <LearnerFormDialog
                    :colleges="colleges"
                    :registration-terms="registrationTerms"
                    :default-registration-term="defaultRegistrationTerm"
                >
                    <Button><Plus class="h-4 w-4" /> Admit a learner</Button>
                </LearnerFormDialog>
            </div>
        </div>

        <div class="flex flex-wrap gap-3">
            <Input
                v-model="filters.search"
                class="max-w-xs"
                placeholder="Search name, email or student number"
                aria-label="Search learners"
            />
            <select
                v-model="filters.college"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                aria-label="College"
                @change="filters.program = null"
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
            <select
                v-model="filters.program"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                aria-label="Program"
            >
                <option :value="null">All programs</option>
                <option
                    v-for="program in programs"
                    :key="program.id"
                    :value="program.id"
                >
                    {{ program.code }}
                </option>
            </select>
            <select
                v-model="filters.year"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                aria-label="Year level"
            >
                <option :value="null">All years</option>
                <option v-for="year in 6" :key="year" :value="year">
                    Year {{ year }}
                </option>
            </select>
            <select
                v-model="filters.status"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                aria-label="Status"
            >
                <option :value="null">Any status</option>
                <option
                    v-for="(label, status) in statusLabels"
                    :key="status"
                    :value="status"
                >
                    {{ label }}
                </option>
            </select>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Student number</th>
                        <th class="px-4 py-2 font-medium">Program</th>
                        <th class="px-4 py-2 font-medium">Year</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="learner in learners.data"
                        :key="learner.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2">
                            <div class="font-medium">
                                {{ learner.user.last_name }},
                                {{ learner.user.first_name }}
                                <Badge
                                    v-if="!learner.user.is_active"
                                    variant="secondary"
                                    class="ml-2"
                                    >Can't log in</Badge
                                >
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ learner.user.email }}
                            </div>
                        </td>
                        <td class="px-4 py-2 font-mono text-xs">
                            {{ learner.student_number }}
                        </td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{ learner.program.code }}
                            <span class="text-muted-foreground"
                                >· {{ learner.program.college.code }}</span
                            >
                        </td>
                        <td class="px-4 py-2">{{ learner.year_level }}</td>
                        <td class="px-4 py-2">
                            {{ statusLabels[learner.status] }}
                        </td>
                        <td class="px-4 py-2">
                            <div class="flex justify-end gap-1">
                                <LearnerFormDialog
                                    :colleges="colleges"
                                    :learner="learner"
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        title="Edit"
                                    >
                                        <Pencil class="h-4 w-4" />
                                        <span class="sr-only">Edit</span>
                                    </Button>
                                </LearnerFormDialog>
                                <ConfirmActionDialog
                                    v-if="learner.user.is_active"
                                    title="Send a new setup link?"
                                    :description="`${learner.user.full_name} gets an email with a link to set a new password, and you'll see the link to copy. Their current password keeps working until they use it.`"
                                    confirm-label="Make link"
                                    :url="
                                        LearnerController.setupLink.url(
                                            learner.id,
                                        )
                                    "
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        title="New setup link"
                                    >
                                        <KeyRound class="h-4 w-4" />
                                        <span class="sr-only"
                                            >New setup link</span
                                        >
                                    </Button>
                                </ConfirmActionDialog>
                                <ConfirmActionDialog
                                    v-if="learner.user.is_active"
                                    :title="`Stop ${learner.user.full_name} from logging in?`"
                                    description="Their records stay, and you can let them back in at any time."
                                    confirm-label="Deactivate"
                                    destructive
                                    method="put"
                                    :data="{ active: false }"
                                    :url="
                                        LearnerController.status.url(learner.id)
                                    "
                                >
                                    <Button variant="ghost" size="sm"
                                        >Deactivate</Button
                                    >
                                </ConfirmActionDialog>
                                <ConfirmActionDialog
                                    v-else
                                    :title="`Let ${learner.user.full_name} log in again?`"
                                    description="They'll be able to log in with their password."
                                    confirm-label="Reactivate"
                                    method="put"
                                    :data="{ active: true }"
                                    :url="
                                        LearnerController.status.url(learner.id)
                                    "
                                >
                                    <Button variant="ghost" size="sm"
                                        >Reactivate</Button
                                    >
                                </ConfirmActionDialog>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="learners.data.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No learners match.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="learners" />
    </div>
</template>

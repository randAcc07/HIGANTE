<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Lock, LockOpen, Pencil, Plus } from '@lucide/vue';
import TermController from '@/actions/App/Http/Controllers/Admin/TermController';
import TermFormDialog from '@/components/admin/TermFormDialog.vue';
import ConfirmDeleteDialog from '@/components/ConfirmDeleteDialog.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateRange } from '@/lib/dates';
import type { AttendancePolicy, Term, TermPhase } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Terms', href: TermController.index() }],
    },
});

defineProps<{
    terms: Term[];
    handbookPolicy: AttendancePolicy;
}>();

const phases: Record<
    TermPhase,
    { label: string; variant: 'default' | 'secondary' | 'outline' }
> = {
    upcoming: { label: 'Upcoming', variant: 'outline' },
    in_session: { label: 'In session', variant: 'default' },
    ended: { label: 'Ended', variant: 'secondary' },
    closed: { label: 'Closed', variant: 'secondary' },
};

function close(term: Term): void {
    router.post(
        TermController.close.url(term.id),
        {},
        { preserveScroll: true },
    );
}

function reopen(term: Term): void {
    router.delete(TermController.reopen.url(term.id), { preserveScroll: true });
}

function policySummary(policy: AttendancePolicy | null | undefined): string {
    if (!policy) {
        return '—';
    }

    const warnings = policy.warn_at_absences.length
        ? `warn at ${policy.warn_at_absences.join(' and ')}`
        : 'no warnings';

    const limit =
        policy.teacher_edit_days === null
            ? ''
            : ` · teachers edit ${policy.teacher_edit_days === 0 ? 'on the day only' : `up to ${policy.teacher_edit_days} day${policy.teacher_edit_days === 1 ? '' : 's'} after`}`;

    return `${policy.lates_per_absence} lates = 1 absence · ${warnings} · drop at ${policy.drop_at_absences}${limit}`;
}
</script>

<template>
    <Head title="Terms" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Terms"
                description="Semesters and the attendance policy each one follows"
            />
            <TermFormDialog :handbook-policy="handbookPolicy">
                <Button><Plus class="h-4 w-4" /> Add term</Button>
            </TermFormDialog>
        </div>

        <div v-table-scroll class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Term</th>
                        <th class="px-4 py-2 font-medium">Dates</th>
                        <th class="px-4 py-2 font-medium">Attendance policy</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Sections
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Registered
                        </th>
                        <th class="table-actions px-4 py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="term in terms" :key="term.id" class="border-t">
                        <td class="px-4 py-2 font-medium whitespace-nowrap">
                            {{ term.label }}
                            <Badge
                                v-if="term.phase"
                                :variant="phases[term.phase].variant"
                                class="ml-2"
                                >{{ phases[term.phase].label }}</Badge
                            >
                        </td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{ formatDateRange(term.starts_on, term.ends_on) }}
                        </td>
                        <td class="px-4 py-2">
                            {{ policySummary(term.attendance_policy) }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            {{ term.sections_count }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            {{ term.term_registrations_count }}
                        </td>
                        <td class="table-actions px-4 py-2">
                            <div class="flex justify-end gap-1">
                                <Button
                                    v-if="term.phase === 'ended'"
                                    variant="ghost"
                                    size="sm"
                                    title="Close the term: its records become read-only"
                                    @click="close(term)"
                                >
                                    <Lock class="h-4 w-4" /> Close
                                </Button>
                                <Button
                                    v-if="term.phase === 'closed'"
                                    variant="ghost"
                                    size="sm"
                                    title="Reopen the term to correct its records"
                                    @click="reopen(term)"
                                >
                                    <LockOpen class="h-4 w-4" /> Reopen
                                </Button>
                                <TermFormDialog
                                    v-if="term.phase !== 'closed'"
                                    :handbook-policy="handbookPolicy"
                                    :term="term"
                                >
                                    <Button variant="ghost" size="sm">
                                        <Pencil class="h-4 w-4" />
                                        <span class="sr-only">Edit</span>
                                    </Button>
                                </TermFormDialog>
                                <ConfirmDeleteDialog
                                    :title="`Delete ${term.label}?`"
                                    description="Only a term with no sections or registrations can be deleted."
                                    :url="TermController.destroy.url(term.id)"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="terms.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No terms yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

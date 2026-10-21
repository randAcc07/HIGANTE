<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import CoverController from '@/actions/App/Http/Controllers/Dean/CoverController';
import ConfirmDeleteDialog from '@/components/ConfirmDeleteDialog.vue';
import ArrangeCoverDialog from '@/components/dean/ArrangeCoverDialog.vue';
import type { PlanRow } from '@/components/dean/ArrangeCoverDialog.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/native-select';
import type { TeacherOption } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Cover', href: CoverController.index() }],
    },
});

type CoverRow = {
    id: number;
    range: string;
    state: 'past' | 'current' | 'upcoming';
    class: string;
    when: string;
    teacher: string;
    substitute: string;
};

defineProps<{
    terms: { id: number; label: string }[];
    term: {
        id: number;
        label: string;
        starts_on: string;
        ends_on: string;
    } | null;
    covers: CoverRow[];
    teachers: TeacherOption[];
    canEdit: boolean;
    plan?: PlanRow[];
}>();

const stateLabels: Record<CoverRow['state'], string> = {
    past: 'Ended',
    current: 'Now',
    upcoming: 'Upcoming',
};

function showTerm(id: number): void {
    router.get(CoverController.index.url(), { term: id });
}
</script>

<template>
    <Head title="Cover" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Cover"
                :description="
                    term
                        ? `${term.label} · substitutes for teachers away for some days`
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
                        {{ option.label }}
                    </option>
                </NativeSelect>
                <ArrangeCoverDialog
                    v-if="term && canEdit"
                    :term="term"
                    :teachers="teachers"
                    :plan="plan"
                >
                    <Button><Plus class="h-4 w-4" /> Arrange cover</Button>
                </ArrangeCoverDialog>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Dates</th>
                        <th class="px-4 py-2 font-medium">Class</th>
                        <th class="px-4 py-2 font-medium">Teacher</th>
                        <th class="px-4 py-2 font-medium">Substitute</th>
                        <th class="px-4 py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="cover in covers"
                        :key="cover.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{ cover.range }}
                            <Badge
                                :variant="
                                    cover.state === 'current'
                                        ? 'default'
                                        : 'secondary'
                                "
                                class="ml-1"
                                >{{ stateLabels[cover.state] }}</Badge
                            >
                        </td>
                        <td class="px-4 py-2">
                            <div class="font-medium">{{ cover.class }}</div>
                            <div class="text-xs text-muted-foreground">
                                {{ cover.when }}
                            </div>
                        </td>
                        <td class="px-4 py-2">{{ cover.teacher }}</td>
                        <td class="px-4 py-2">{{ cover.substitute }}</td>
                        <td class="px-4 py-2">
                            <div v-if="canEdit" class="flex justify-end">
                                <ConfirmDeleteDialog
                                    :title="`Remove ${cover.substitute}'s cover?`"
                                    description="They can no longer mark the class on those days; marks they already made stay. They are told."
                                    :url="CoverController.destroy.url(cover.id)"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="covers.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No cover arranged this term.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

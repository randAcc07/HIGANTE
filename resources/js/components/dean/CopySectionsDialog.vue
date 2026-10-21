<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import SectionCopyController from '@/actions/App/Http/Controllers/Dean/SectionCopyController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

type Proposal = {
    id: number;
    program_id: number;
    program: string;
    from: string;
    year_level: number;
    name: string;
    capacity: number | null;
    ticked: boolean;
};

/**
 * Copy an earlier term's sections into this term: each row is one source
 * section and what its copy will be (year level, name, capacity).
 */
const props = defineProps<{
    termId: number;
    termLabel: string;
    from: { id: number; label: string };
    shiftsUp: boolean;
    proposals: Proposal[];
    /** Sections the term already has, to flag names that are taken. */
    existing: { program_id: number; name: string }[];
}>();

const open = ref(false);
const key = (programId: number, name: string): string =>
    `${programId}|${name.trim().toLowerCase()}`;
const taken = computed(
    () =>
        new Set(
            props.existing.map((section) =>
                key(section.program_id, section.name),
            ),
        ),
);
// A block the term already has (copied before, or made by hand) starts unticked.
const rows = reactive(
    props.proposals.map((proposal) => ({
        ...proposal,
        ticked:
            proposal.ticked &&
            !taken.value.has(key(proposal.program_id, proposal.name)),
        capacity: proposal.capacity === null ? '' : String(proposal.capacity),
    })),
);
const form = useForm({
    term_id: props.termId,
    sections: [] as {
        from_id: number;
        year_level: number;
        name: string;
        capacity: number | null;
    }[],
});

/** Why a ticked row can't be copied as it stands, or null. */
function clash(row: (typeof rows)[number]): string | null {
    if (row.name.trim() === '') {
        return 'Give it a name.';
    }

    if (taken.value.has(key(row.program_id, row.name))) {
        return `${row.program} already has ${row.name.trim()} this term.`;
    }

    const twin = rows.find(
        (other) =>
            other !== row &&
            other.ticked &&
            key(other.program_id, other.name) ===
                key(row.program_id, row.name) &&
            rows.indexOf(other) < rows.indexOf(row),
    );

    return twin ? `Also the name of the copy of ${twin.from}.` : null;
}

const ticked = computed(() => rows.filter((row) => row.ticked));
const blocked = computed(() => ticked.value.some((row) => clash(row)));

/** Server errors come back by position in what was sent. */
function serverError(row: (typeof rows)[number]): string | undefined {
    const at = ticked.value.indexOf(row);
    const errors = form.errors as Record<string, string>;

    return at === -1
        ? undefined
        : (errors[`sections.${at}.name`] ??
              errors[`sections.${at}.from_id`] ??
              errors[`sections.${at}.year_level`] ??
              errors[`sections.${at}.capacity`]);
}

function submit(): void {
    form.sections = ticked.value.map((row) => ({
        from_id: row.id,
        year_level: row.year_level,
        name: row.name.trim(),
        capacity: row.capacity.trim() === '' ? null : Number(row.capacity),
    }));
    form.post(SectionCopyController.store.url(), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
            <DialogTitle>Copy sections from {{ from.label }}</DialogTitle>
            <DialogDescription>
                {{
                    shiftsUp
                        ? `A new school year: each block moves up a year level, and its learners are placed back together when the registrar registers them for ${termLabel}.`
                        : `The same blocks for ${termLabel}; its learners are placed back together when the registrar registers them.`
                }}
                Each copy gets its subjects from the curriculum. Teachers,
                batches and classes are not copied.
            </DialogDescription>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="w-10 px-3 py-2">
                                <span class="sr-only">Copy</span>
                            </th>
                            <th class="px-3 py-2 font-medium">From</th>
                            <th class="px-3 py-2 font-medium">Year</th>
                            <th class="px-3 py-2 font-medium">Name</th>
                            <th class="px-3 py-2 font-medium">Capacity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in rows"
                            :key="row.id"
                            class="border-t align-top"
                        >
                            <td class="px-3 py-2">
                                <Checkbox
                                    v-model="row.ticked"
                                    :aria-label="`Copy ${row.program} ${row.from}`"
                                />
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                {{ row.program }} {{ row.from }}
                            </td>
                            <td class="px-3 py-2">
                                <select
                                    v-model.number="row.year_level"
                                    :disabled="!row.ticked"
                                    class="h-8 rounded-md border border-input bg-transparent px-2 text-sm"
                                    :aria-label="`Year level for ${row.program} ${row.from}`"
                                >
                                    <option
                                        v-for="year in 6"
                                        :key="year"
                                        :value="year"
                                    >
                                        {{ year }}
                                    </option>
                                </select>
                            </td>
                            <td class="px-3 py-2">
                                <Input
                                    v-model="row.name"
                                    :disabled="!row.ticked"
                                    class="h-8 w-28"
                                    :aria-label="`Name for ${row.program} ${row.from}`"
                                />
                                <InputError
                                    v-if="row.ticked"
                                    :message="clash(row) ?? serverError(row)"
                                />
                            </td>
                            <td class="px-3 py-2">
                                <Input
                                    v-model="row.capacity"
                                    :disabled="!row.ticked"
                                    type="number"
                                    min="1"
                                    placeholder="—"
                                    class="h-8 w-20"
                                    :aria-label="`Capacity for ${row.program} ${row.from}`"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="shiftsUp" class="text-xs text-muted-foreground">
                Sections moving past the years the curriculum covers start
                unticked (a graduating year). New 1st-year blocks are added by
                hand.
            </p>
            <InputError :message="form.errors.sections" />

            <DialogFooter>
                <Button
                    :disabled="form.processing || !ticked.length || blocked"
                    @click="submit"
                    >Copy {{ ticked.length }} section{{
                        ticked.length === 1 ? '' : 's'
                    }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import BatchController from '@/actions/App/Http/Controllers/Dean/BatchController';
import LearnerController from '@/actions/App/Http/Controllers/Dean/LearnerController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { NativeSelect } from '@/components/ui/native-select';
import { newcomerCounts } from '@/lib/batchSplit';
import type { Offering, RosterRow } from '@/types';

const props = defineProps<{
    sectionId: number;
    roster: RosterRow[];
    offerings: Offering[];
    canEdit: boolean;
}>();

/** Subjects split into batches get a column each. */
const split = computed(() =>
    props.offerings.filter((offering) => offering.batches.length > 0),
);

/** Batch names across the split subjects, for "set all". */
const names = computed(() =>
    [
        ...new Set(
            split.value.flatMap((offering) =>
                offering.batches.map((batch) => batch.name),
            ),
        ),
    ].sort(),
);

/** Enrollment id → chosen batch id. */
const chosen = reactive<Record<number, number | null>>({});

/** Learner ids ticked for "put in". */
const ticked = reactive(new Set<number>());

watch(
    () => props.roster,
    (roster) => {
        ticked.clear();

        for (const key of Object.keys(chosen)) {
            delete chosen[Number(key)];
        }

        for (const row of roster) {
            for (const enrollment of Object.values(row.enrollments)) {
                chosen[enrollment.id] = enrollment.batch_id;
            }
        }
    },
    { immediate: true },
);

const changed = computed(() =>
    props.roster.flatMap((row) =>
        Object.values(row.enrollments)
            .filter(
                (enrollment) => chosen[enrollment.id] !== enrollment.batch_id,
            )
            .map((enrollment) => ({
                enrollment_id: enrollment.id,
                batch_id: chosen[enrollment.id],
            })),
    ),
);

/** Put a learner in the batch of that name, in every split subject or one. */
function setAll(row: RosterRow, name: string, offeringId?: number): void {
    for (const offering of split.value) {
        const enrollment = row.enrollments[offering.id];
        const batch = offering.batches.find((batch) => batch.name === name);

        if (
            enrollment &&
            batch &&
            (offeringId === undefined || offering.id === offeringId)
        ) {
            chosen[enrollment.id] = batch.id;
        }
    }
}

/** The batch name a learner is in (the first split subject that has one). */
function nameOf(row: RosterRow): string | null {
    for (const offering of split.value) {
        const enrollment = row.enrollments[offering.id];
        const batch = enrollment
            ? offering.batches.find(
                  (batch) => batch.id === chosen[enrollment.id],
              )
            : undefined;

        if (batch) {
            return batch.name;
        }
    }

    return null;
}

/** Learners in a split subject, for rows that take none. */
function takesSplit(row: RosterRow): boolean {
    return split.value.some((offering) => row.enrollments[offering.id]);
}

/** What the last automatic split did, until saved or reloaded. */
const splitNote = ref<string | null>(null);

/**
 * Split everyone without a batch by last name, in blocks, sized toward an
 * even split within each batch's lab seats. Learners with a batch stay, and
 * get the same batch in any split subject they lack one in.
 */
function splitAutomatically(): void {
    const rows = props.roster.filter(takesSplit);
    const newcomers: RosterRow[] = [];

    for (const row of rows) {
        const name = nameOf(row);

        if (name === null) {
            newcomers.push(row);
            continue;
        }

        for (const offering of split.value) {
            const enrollment = row.enrollments[offering.id];

            if (enrollment && chosen[enrollment.id] === null) {
                setAll(row, name, offering.id);
            }
        }
    }

    if (newcomers.length === 0) {
        splitNote.value = 'Everyone already has a batch.';

        return;
    }

    newcomers.sort((a, b) => a.name.localeCompare(b.name));

    const slots = names.value.map((name) => {
        const seats = split.value
            .flatMap((offering) => offering.batches)
            .filter((batch) => batch.name === name)
            .map((batch) => batch.room_capacity ?? null)
            .filter((capacity): capacity is number => capacity !== null);

        return {
            name,
            capacity: seats.length ? Math.min(...seats) : null,
            current: rows.filter((row) => nameOf(row) === name).length,
        };
    });
    const { counts, over } = newcomerCounts(slots, newcomers.length);
    const parts: string[] = [];
    let next = 0;

    slots.forEach((slot, i) => {
        const block = newcomers.slice(next, next + counts[i]);
        next += counts[i];
        block.forEach((row) => setAll(row, slot.name));

        if (block.length) {
            const first = block[0].name.charAt(0).toUpperCase();
            const last = block[block.length - 1].name.charAt(0).toUpperCase();
            parts.push(
                `${slot.name} +${block.length} (${first === last ? first : `${first}–${last}`})`,
            );
        }
    });

    splitNote.value =
        `Placed ${newcomers.length} by last name: ${parts.join(', ')}.` +
        (over.length
            ? ` ${over.join(', ')} is past its lab's seats: every lab was full.`
            : '') +
        ' Review, then save batches.';
}

/* Tick and move */
const moveTo = ref<string | null>(null);
const moveIn = ref<number | null>(null);

const tickable = computed(() => props.roster.filter(takesSplit));
const allTicked = computed<boolean | 'indeterminate'>(() =>
    ticked.size === 0
        ? false
        : ticked.size === tickable.value.length
          ? true
          : 'indeterminate',
);

function tickAll(value: boolean | 'indeterminate'): void {
    ticked.clear();

    if (value === true) {
        tickable.value.forEach((row) => ticked.add(row.learner_id));
    }
}

function tick(row: RosterRow, value: boolean | 'indeterminate'): void {
    if (value === true) {
        ticked.add(row.learner_id);
    } else {
        ticked.delete(row.learner_id);
    }
}

function moveTicked(): void {
    if (moveTo.value === null) {
        return;
    }

    for (const row of props.roster) {
        if (ticked.has(row.learner_id)) {
            setAll(row, moveTo.value, moveIn.value ?? undefined);
        }
    }

    ticked.clear();
}

/** How many learners each batch has with the unsaved choices. */
function headcount(batchId: number): number {
    return Object.values(chosen).filter((id) => id === batchId).length;
}

function unbatched(offering: Offering): number {
    return props.roster.filter((row) => {
        const enrollment = row.enrollments[offering.id];

        return enrollment && chosen[enrollment.id] === null;
    }).length;
}

const form = useForm<{
    batches: { enrollment_id: number; batch_id: number | null }[];
}>({ batches: [] });

const errors = computed(() => Object.values(form.errors));

function save(): void {
    form.batches = changed.value;
    form.put(BatchController.roster.url(props.sectionId), {
        preserveScroll: true,
        onSuccess: () => (splitNote.value = null),
    });
}

function onSetAll(row: RosterRow, event: Event): void {
    const select = event.target as HTMLSelectElement;

    if (select.value) {
        setAll(row, select.value);
    }

    select.value = '';
}
</script>

<template>
    <div class="space-y-3">
        <p
            v-if="split.length === 0 && roster.length"
            class="rounded-lg border p-3 text-sm text-muted-foreground"
        >
            No subject is split into batches. To split one, add batches to it
            with "+ Batch" under Subjects; this list then lets you place
            learners in them.
        </p>

        <div
            v-if="canEdit && split.length && tickable.length"
            class="flex flex-wrap items-center gap-3"
        >
            <Button variant="outline" @click="splitAutomatically"
                >Split automatically</Button
            >
            <span class="text-sm text-muted-foreground"
                >Learners without a batch, by last name; those with one
                stay.</span
            >
            <div
                v-if="ticked.size"
                class="ml-auto flex flex-wrap items-center gap-2 text-sm"
            >
                <span>{{ ticked.size }} ticked: put in</span>
                <NativeSelect
                    size="sm"
                    v-model="moveTo"
                    aria-label="Batch to put the ticked learners in"
                >
                    <option :value="null" disabled>batch…</option>
                    <option v-for="name in names" :key="name" :value="name">
                        {{ name }}
                    </option>
                </NativeSelect>
                <NativeSelect
                    size="sm"
                    v-model="moveIn"
                    aria-label="Subjects to apply it to"
                >
                    <option :value="null">in every split subject</option>
                    <option
                        v-for="offering in split"
                        :key="offering.id"
                        :value="offering.id"
                    >
                        in {{ offering.subject.code }} only
                    </option>
                </NativeSelect>
                <Button
                    size="sm"
                    :disabled="moveTo === null"
                    @click="moveTicked"
                    >Apply</Button
                >
                <Button size="sm" variant="ghost" @click="ticked.clear()"
                    >Clear</Button
                >
            </div>
        </div>

        <p
            v-if="splitNote"
            class="rounded-lg border border-info/50 bg-info-soft p-3 text-sm text-info-soft-foreground"
        >
            {{ splitNote }}
        </p>

        <div
            v-if="errors.length"
            class="rounded-lg border border-destructive/50 p-3 text-sm text-destructive"
            role="alert"
        >
            <p v-for="error in errors" :key="error">{{ error }}</p>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th
                            v-if="canEdit && split.length"
                            class="w-10 px-4 py-2"
                        >
                            <Checkbox
                                :model-value="allTicked"
                                aria-label="Tick everyone"
                                @update:model-value="tickAll"
                            />
                        </th>
                        <th class="px-4 py-2 font-medium">Learner</th>
                        <th
                            v-if="canEdit && names.length"
                            class="px-4 py-2 font-medium"
                        >
                            Set all
                        </th>
                        <th
                            v-for="offering in split"
                            :key="offering.id"
                            class="px-4 py-2 font-medium whitespace-nowrap"
                        >
                            {{ offering.subject.code }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in roster"
                        :key="row.learner_id"
                        class="border-t"
                    >
                        <td v-if="canEdit && split.length" class="px-4 py-2">
                            <Checkbox
                                v-if="takesSplit(row)"
                                :model-value="ticked.has(row.learner_id)"
                                :aria-label="`Tick ${row.name}`"
                                @update:model-value="tick(row, $event)"
                            />
                        </td>
                        <td class="px-4 py-2">
                            <Link
                                v-if="row.registration_id"
                                :href="
                                    LearnerController.show(row.registration_id)
                                "
                                class="font-medium hover:underline"
                                >{{ row.name }}</Link
                            >
                            <span v-else class="font-medium">{{
                                row.name
                            }}</span>
                            <Badge
                                v-if="row.from_section"
                                variant="outline"
                                class="ml-2"
                                >from {{ row.from_section }}</Badge
                            >
                            <Badge
                                v-else-if="row.is_irregular"
                                variant="outline"
                                class="ml-2"
                                >Irregular</Badge
                            >
                            <div
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ row.student_number }}
                            </div>
                        </td>
                        <td v-if="canEdit && names.length" class="px-4 py-2">
                            <NativeSelect
                                size="sm"
                                :aria-label="`Set all batches for ${row.name}`"
                                @change="onSetAll(row, $event)"
                            >
                                <option value="">…</option>
                                <option
                                    v-for="name in names"
                                    :key="name"
                                    :value="name"
                                >
                                    {{ name }}
                                </option>
                            </NativeSelect>
                        </td>
                        <td
                            v-for="offering in split"
                            :key="offering.id"
                            class="px-4 py-2"
                        >
                            <NativeSelect
                                size="sm"
                                v-if="row.enrollments[offering.id]"
                                v-model="
                                    chosen[row.enrollments[offering.id].id]
                                "
                                :disabled="!canEdit"
                                :aria-label="`${row.name}'s batch in ${offering.subject.code}`"
                            >
                                <option :value="null">—</option>
                                <option
                                    v-for="batch in offering.batches"
                                    :key="batch.id"
                                    :value="batch.id"
                                >
                                    {{ batch.name }}
                                </option>
                            </NativeSelect>
                            <span v-else class="text-muted-foreground"
                                >Not taking</span
                            >
                        </td>
                    </tr>
                    <tr v-if="roster.length === 0">
                        <td
                            :colspan="split.length + 3"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            Nobody takes the section's subjects yet.
                        </td>
                    </tr>
                </tbody>
                <tfoot
                    v-if="split.length && roster.length"
                    class="border-t bg-muted/50 text-xs text-muted-foreground"
                >
                    <tr>
                        <td v-if="canEdit" class="px-4 py-2"></td>
                        <td class="px-4 py-2">Learners per batch</td>
                        <td v-if="canEdit && names.length"></td>
                        <td
                            v-for="offering in split"
                            :key="offering.id"
                            class="px-4 py-2 whitespace-nowrap"
                        >
                            <div
                                v-for="batch in offering.batches"
                                :key="batch.id"
                            >
                                {{ batch.name }}: {{ headcount(batch.id)
                                }}<template v-if="batch.room_capacity">
                                    of {{ batch.room_capacity }} seats</template
                                >
                            </div>
                            <div v-if="unbatched(offering)">
                                None: {{ unbatched(offering) }}
                            </div>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div
            v-if="canEdit && split.length && roster.length"
            class="flex items-center justify-end gap-3"
        >
            <span class="text-sm text-muted-foreground"
                >{{ changed.length }} unsaved change{{
                    changed.length === 1 ? '' : 's'
                }}</span
            >
            <Button
                :disabled="changed.length === 0 || form.processing"
                @click="save"
                >Save batches</Button
            >
        </div>
    </div>
</template>

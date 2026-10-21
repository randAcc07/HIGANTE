<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import CoverController from '@/actions/App/Http/Controllers/Dean/CoverController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/dates';
import type { TeacherOption } from '@/types';

export type PlanRow = {
    schedule_id: number;
    class: string;
    when: string;
    dates: string[];
    /** Someone already covering it in part of the range. */
    covered_by: string | null;
};

/**
 * Arrange cover: a teacher and dates, then a substitute for each of their
 * classes meeting then (the plan comes from the server as the choices are
 * made).
 */
const props = defineProps<{
    term: { id: number; label: string; starts_on: string; ends_on: string };
    teachers: TeacherOption[];
    plan: PlanRow[] | undefined;
}>();

const open = ref(false);
const loading = ref(false);
const form = useForm({
    teacher_id: null as number | null,
    starts_on: '',
    ends_on: '',
    /** schedule id => substitute id (null: no cover). */
    substitutes: {} as Record<number, number | null>,
});
const everyone = ref<number | null>(null);

const rows = computed(() => props.plan ?? []);
const substitutes = computed(() =>
    props.teachers.filter((teacher) => teacher.id !== form.teacher_id),
);
const chosen = computed(
    () =>
        rows.value.filter((row) => form.substitutes[row.schedule_id] != null)
            .length,
);

watch(
    () => [form.teacher_id, form.starts_on, form.ends_on],
    () => {
        if (!form.teacher_id || !form.starts_on || !form.ends_on) {
            return;
        }

        loading.value = true;
        router.reload({
            only: ['plan'],
            data: {
                teacher: form.teacher_id,
                from: form.starts_on,
                to: form.ends_on,
            },
            onFinish: () => (loading.value = false),
        });
    },
);

watch(rows, (current) => {
    form.substitutes = Object.fromEntries(
        current.map((row) => [
            row.schedule_id,
            row.covered_by ? null : everyone.value,
        ]),
    );
});

function setEveryone(id: number | null): void {
    everyone.value = id;

    for (const row of rows.value) {
        if (!row.covered_by) {
            form.substitutes[row.schedule_id] = id;
        }
    }
}

function rowError(index: number): string | undefined {
    const errors = form.errors as Record<string, string>;

    return (
        errors[`covers.${index}.substitute_id`] ??
        errors[`covers.${index}.schedule_id`]
    );
}

function dates(row: PlanRow): string {
    return row.dates
        .map((date) => formatDate(date, { month: 'short', day: 'numeric' }))
        .join(', ');
}

function submit(): void {
    form.transform((data) => ({
        term_id: props.term.id,
        teacher_id: data.teacher_id,
        starts_on: data.starts_on,
        ends_on: data.ends_on,
        covers: rows.value.map((row) => ({
            schedule_id: row.schedule_id,
            substitute_id: data.substitutes[row.schedule_id] ?? null,
        })),
    })).post(CoverController.store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
            everyone.value = null;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
            <DialogTitle>Arrange cover</DialogTitle>
            <DialogDescription>
                A substitute marks attendance for the classes they cover on
                those days. The classes keep their teacher, who still decides
                excuses.
            </DialogDescription>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="cover-teacher">Teacher away</Label>
                        <select
                            id="cover-teacher"
                            v-model="form.teacher_id"
                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option :value="null" disabled>Choose…</option>
                            <option
                                v-for="teacher in teachers"
                                :key="teacher.id"
                                :value="teacher.id"
                            >
                                {{ teacher.name
                                }}{{ teacher.on_leave ? ' (on leave)' : '' }}
                            </option>
                        </select>
                        <InputError :message="form.errors.teacher_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="cover-from">From</Label>
                        <Input
                            id="cover-from"
                            v-model="form.starts_on"
                            type="date"
                            :min="term.starts_on"
                            :max="term.ends_on"
                        />
                        <InputError :message="form.errors.starts_on" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="cover-to">To</Label>
                        <Input
                            id="cover-to"
                            v-model="form.ends_on"
                            type="date"
                            :min="form.starts_on || term.starts_on"
                            :max="term.ends_on"
                        />
                        <InputError :message="form.errors.ends_on" />
                    </div>
                </div>

                <template
                    v-if="form.teacher_id && form.starts_on && form.ends_on"
                >
                    <p
                        v-if="!loading && rows.length === 0"
                        class="rounded-lg border p-4 text-center text-sm text-muted-foreground"
                    >
                        No classes of theirs meet on those days.
                    </p>

                    <template v-else-if="rows.length">
                        <div class="flex flex-wrap items-center gap-2">
                            <Label for="cover-everyone"
                                >Same substitute for all</Label
                            >
                            <select
                                id="cover-everyone"
                                :value="everyone"
                                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                                @change="
                                    setEveryone(
                                        ($event.target as HTMLSelectElement)
                                            .value === ''
                                            ? null
                                            : Number(
                                                  (
                                                      $event.target as HTMLSelectElement
                                                  ).value,
                                              ),
                                    )
                                "
                            >
                                <option value="">—</option>
                                <option
                                    v-for="teacher in substitutes"
                                    :key="teacher.id"
                                    :value="teacher.id"
                                >
                                    {{ teacher.name }}
                                </option>
                            </select>
                        </div>

                        <div class="overflow-x-auto rounded-lg border">
                            <table class="w-full text-sm">
                                <thead
                                    class="bg-muted/50 text-left text-muted-foreground"
                                >
                                    <tr>
                                        <th class="px-3 py-2 font-medium">
                                            Class
                                        </th>
                                        <th class="px-3 py-2 font-medium">
                                            Meets
                                        </th>
                                        <th class="px-3 py-2 font-medium">
                                            Substitute
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(row, index) in rows"
                                        :key="row.schedule_id"
                                        class="border-t align-top"
                                    >
                                        <td class="px-3 py-2">
                                            <div class="font-medium">
                                                {{ row.class }}
                                            </div>
                                            <div
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{ row.when }}
                                            </div>
                                        </td>
                                        <td
                                            class="px-3 py-2 text-muted-foreground"
                                        >
                                            {{ dates(row) }}
                                        </td>
                                        <td class="px-3 py-2">
                                            <span
                                                v-if="row.covered_by"
                                                class="text-muted-foreground"
                                                >Covered by
                                                {{ row.covered_by }}</span
                                            >
                                            <select
                                                v-else
                                                v-model="
                                                    form.substitutes[
                                                        row.schedule_id
                                                    ]
                                                "
                                                class="h-8 w-full rounded-md border border-input bg-transparent px-2 text-sm"
                                                :aria-label="`Substitute for ${row.class}`"
                                            >
                                                <option :value="null">
                                                    No cover
                                                </option>
                                                <option
                                                    v-for="teacher in substitutes"
                                                    :key="teacher.id"
                                                    :value="teacher.id"
                                                >
                                                    {{ teacher.name }}
                                                </option>
                                            </select>
                                            <InputError
                                                :message="rowError(index)"
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                </template>
                <InputError
                    :message="(form.errors as Record<string, string>).covers"
                />

                <DialogFooter>
                    <Button :disabled="form.processing || chosen === 0"
                        >Arrange cover for {{ chosen }} class{{
                            chosen === 1 ? '' : 'es'
                        }}</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

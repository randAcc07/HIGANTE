<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CurriculumController from '@/actions/App/Http/Controllers/Dean/CurriculumController';
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
import { Label } from '@/components/ui/label';
import type { CurriculumLine, Semester, Subject } from '@/types';

/**
 * Add subjects to a year level and semester of the program's curriculum,
 * or (with `line`) move one subject to another.
 */
const props = defineProps<{
    programId: number;
    semesters: { value: Semester; label: string }[];
    subjects?: Pick<Subject, 'id' | 'code' | 'title'>[];
    line?: CurriculumLine;
    /** Where new subjects go, when added from a year and semester's block. */
    yearLevel?: number;
    semester?: Semester;
}>();

const open = ref(false);
const search = ref('');
const form = useForm({
    year_level: props.line?.year_level ?? props.yearLevel ?? 1,
    semester: props.line?.semester ?? props.semester ?? 'first',
    subject_ids: [] as number[],
});

const shown = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return (props.subjects ?? []).filter(
        (subject) =>
            needle === '' ||
            `${subject.code} ${subject.title}`.toLowerCase().includes(needle),
    );
});

const subjectErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('subject_ids'))
        .map(([, message]) => message),
);

function tick(id: number, on: boolean | 'indeterminate'): void {
    form.subject_ids = on
        ? [...form.subject_ids, id]
        : form.subject_ids.filter((ticked) => ticked !== id);
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;

            if (!props.line) {
                form.reset('subject_ids');
                search.value = '';
            }
        },
    };

    if (props.line) {
        form.transform((data) => ({
            year_level: data.year_level,
            semester: data.semester,
        })).put(CurriculumController.update.url(props.line.id), options);
    } else {
        form.transform((data) => data).post(
            CurriculumController.store.url(props.programId),
            options,
        );
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent class="max-h-[90vh] overflow-y-auto">
            <DialogTitle>{{
                line ? `Move ${line.subject.code}` : 'Add subjects'
            }}</DialogTitle>
            <DialogDescription>
                {{
                    line
                        ? 'New sections of that year and semester get it.'
                        : "Your college's subjects not yet in this curriculum."
                }}
            </DialogDescription>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="curriculum-year">Year level</Label>
                        <select
                            id="curriculum-year"
                            v-model.number="form.year_level"
                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option v-for="year in 6" :key="year" :value="year">
                                Year {{ year }}
                            </option>
                        </select>
                        <InputError :message="form.errors.year_level" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="curriculum-semester">Semester</Label>
                        <select
                            id="curriculum-semester"
                            v-model="form.semester"
                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option
                                v-for="option in semesters"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <InputError :message="form.errors.semester" />
                    </div>
                </div>

                <div v-if="!line" class="grid gap-2">
                    <Label for="curriculum-search"
                        >Subjects ({{ form.subject_ids.length }} ticked)</Label
                    >
                    <Input
                        id="curriculum-search"
                        v-model="search"
                        placeholder="Search code or title"
                    />
                    <div class="max-h-64 overflow-y-auto rounded-md border">
                        <Label
                            v-for="subject in shown"
                            :key="subject.id"
                            class="flex items-center gap-2 border-b px-3 py-2 font-normal last:border-b-0"
                        >
                            <Checkbox
                                :model-value="
                                    form.subject_ids.includes(subject.id)
                                "
                                @update:model-value="tick(subject.id, $event)"
                            />
                            <span class="font-medium">{{ subject.code }}</span>
                            <span class="text-muted-foreground">{{
                                subject.title
                            }}</span>
                        </Label>
                        <p
                            v-if="shown.length === 0"
                            class="px-3 py-4 text-center text-sm text-muted-foreground"
                        >
                            No subjects match.
                        </p>
                    </div>
                    <InputError
                        v-for="error in subjectErrors"
                        :key="error"
                        :message="error"
                    />
                </div>

                <DialogFooter>
                    <Button
                        :disabled="
                            form.processing ||
                            (!line && form.subject_ids.length === 0)
                        "
                        >{{
                            line
                                ? 'Move'
                                : `Add ${form.subject_ids.length || ''}`.trim()
                        }}</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

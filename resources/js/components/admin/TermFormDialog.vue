<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import TermController from '@/actions/App/Http/Controllers/Admin/TermController';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { AttendancePolicy, Semester, Term } from '@/types';

const props = defineProps<{
    handbookPolicy: AttendancePolicy;
    term?: Term;
}>();

const policy = props.term?.attendance_policy ?? props.handbookPolicy;
const open = ref(false);
const form = useForm({
    school_year: props.term?.school_year ?? '',
    semester: (props.term?.semester ?? 'first') as Semester,
    starts_on: props.term?.starts_on ?? '',
    ends_on: props.term?.ends_on ?? '',
    lates_per_absence: String(policy.lates_per_absence),
    drop_at_absences: String(policy.drop_at_absences),
    // Typed as "3, 4"; sent as a list.
    warn_at_absences: policy.warn_at_absences.join(', '),
    // Empty is no limit.
    teacher_edit_days:
        policy.teacher_edit_days === null
            ? ''
            : String(policy.teacher_edit_days),
});

function submit(): void {
    form.transform((data) => ({
        ...data,
        lates_per_absence: Number(data.lates_per_absence),
        drop_at_absences: Number(data.drop_at_absences),
        teacher_edit_days:
            data.teacher_edit_days.trim() === ''
                ? null
                : Number(data.teacher_edit_days),
        warn_at_absences: data.warn_at_absences
            .split(',')
            .map((count) => count.trim())
            .filter((count) => count !== '')
            .map(Number),
    })).submit(
        props.term
            ? TermController.update(props.term.id)
            : TermController.store(),
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;

                if (!props.term) {
                    form.reset();
                }
            },
        },
    );
}

function useHandbook(): void {
    form.lates_per_absence = String(props.handbookPolicy.lates_per_absence);
    form.drop_at_absences = String(props.handbookPolicy.drop_at_absences);
    form.warn_at_absences = props.handbookPolicy.warn_at_absences.join(', ');
    form.teacher_edit_days =
        props.handbookPolicy.teacher_edit_days === null
            ? ''
            : String(props.handbookPolicy.teacher_edit_days);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent>
            <DialogTitle>{{
                term ? `Edit ${term.label}` : 'Add term'
            }}</DialogTitle>
            <DialogDescription v-if="term?.phase === 'in_session'">
                This term is running. Changing its attendance policy changes how
                every learner's standing is counted.
            </DialogDescription>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="term-year">School year</Label>
                        <Input
                            id="term-year"
                            v-model="form.school_year"
                            placeholder="2026-2027"
                        />
                        <InputError :message="form.errors.school_year" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="term-semester">Semester</Label>
                        <Select v-model="form.semester">
                            <SelectTrigger id="term-semester" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="first"
                                    >1st Semester</SelectItem
                                >
                                <SelectItem value="second"
                                    >2nd Semester</SelectItem
                                >
                                <SelectItem value="summer">Summer</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.semester" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="term-start">First day</Label>
                        <Input
                            id="term-start"
                            v-model="form.starts_on"
                            type="date"
                        />
                        <InputError :message="form.errors.starts_on" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="term-end">Last day</Label>
                        <Input
                            id="term-end"
                            v-model="form.ends_on"
                            type="date"
                        />
                        <InputError :message="form.errors.ends_on" />
                    </div>
                </div>

                <div class="space-y-3 rounded-lg border p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-medium">Attendance policy</p>
                        <Button
                            type="button"
                            variant="link"
                            size="sm"
                            class="h-auto p-0"
                            @click="useHandbook"
                        >
                            Use the handbook's
                        </Button>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="grid gap-2">
                            <Label for="term-lates">Lates per absence</Label>
                            <Input
                                id="term-lates"
                                v-model="form.lates_per_absence"
                                type="number"
                                min="1"
                            />
                            <InputError
                                :message="form.errors.lates_per_absence"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="term-warn">Warn at</Label>
                            <Input
                                id="term-warn"
                                v-model="form.warn_at_absences"
                                placeholder="3, 4"
                            />
                            <InputError
                                :message="form.errors.warn_at_absences"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="term-drop">Drop at</Label>
                            <Input
                                id="term-drop"
                                v-model="form.drop_at_absences"
                                type="number"
                                min="1"
                            />
                            <InputError
                                :message="form.errors.drop_at_absences"
                            />
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Counted per class: unexcused absences plus one for every
                        {{ form.lates_per_absence || '…' }} lates.
                    </p>
                    <div class="grid gap-2">
                        <Label for="term-edit-days"
                            >Teachers can change marks for</Label
                        >
                        <div class="flex items-center gap-2">
                            <Input
                                id="term-edit-days"
                                v-model="form.teacher_edit_days"
                                type="number"
                                min="0"
                                placeholder="No limit"
                                class="w-28"
                            />
                            <span class="text-sm text-muted-foreground"
                                >days after a class</span
                            >
                        </div>
                        <InputError :message="form.errors.teacher_edit_days" />
                        <p class="text-xs text-muted-foreground">
                            Leave empty for no limit; 0 means only on the day.
                            Deans can always correct marks.
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button :disabled="form.processing">Save</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

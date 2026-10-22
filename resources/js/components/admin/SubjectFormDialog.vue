<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import SubjectController from '@/actions/App/Http/Controllers/Admin/SubjectController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
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
import type { College, Subject } from '@/types';

const props = defineProps<{
    colleges: Pick<College, 'id' | 'code' | 'name'>[];
    subject?: Subject;
    defaultCollegeId?: number | null;
}>();

const open = ref(false);
const form = useForm({
    college_id: props.subject?.college_id ?? props.defaultCollegeId ?? null,
    code: props.subject?.code ?? '',
    title: props.subject?.title ?? '',
    lecture_units: props.subject?.lecture_units ?? '3',
    laboratory_units: props.subject?.laboratory_units ?? '0',
});

function submit(): void {
    form.transform((data) =>
        props.subject
            ? {
                  code: data.code,
                  title: data.title,
                  lecture_units: data.lecture_units,
                  laboratory_units: data.laboratory_units,
              }
            : data,
    ).submit(
        props.subject
            ? SubjectController.update(props.subject.id)
            : SubjectController.store(),
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;

                if (!props.subject) {
                    form.reset('code', 'title');
                }
            },
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    subject ? `Edit ${subject.code}` : 'Add subject'
                }}</DialogTitle>
                <DialogDescription v-if="subject">
                    The college can't change once a subject exists.
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div v-if="!subject" class="grid gap-2">
                    <Label for="subject-college">College</Label>
                    <Select v-model="form.college_id">
                        <SelectTrigger id="subject-college" class="w-full">
                            <SelectValue placeholder="Choose a college" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="college in colleges"
                                :key="college.id"
                                :value="college.id"
                                >{{ college.code }} ·
                                {{ college.name }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.college_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="subject-code">Code</Label>
                    <Input
                        id="subject-code"
                        v-model="form.code"
                        placeholder="CS 101"
                    />
                    <InputError :message="form.errors.code" />
                </div>
                <div class="grid gap-2">
                    <Label for="subject-title">Title</Label>
                    <Input
                        id="subject-title"
                        v-model="form.title"
                        placeholder="Introduction to Computing"
                    />
                    <InputError :message="form.errors.title" />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="subject-lecture">Lecture units</Label>
                        <Input
                            id="subject-lecture"
                            v-model="form.lecture_units"
                            type="number"
                            min="0"
                            step="0.5"
                        />
                        <InputError :message="form.errors.lecture_units" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="subject-lab">Laboratory units</Label>
                        <Input
                            id="subject-lab"
                            v-model="form.laboratory_units"
                            type="number"
                            min="0"
                            step="0.5"
                        />
                        <InputError :message="form.errors.laboratory_units" />
                    </div>
                </div>
                <DialogFooter>
                    <Button :disabled="form.processing">Save</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

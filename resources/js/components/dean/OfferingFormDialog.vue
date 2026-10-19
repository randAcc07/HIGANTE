<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import OfferingController from '@/actions/App/Http/Controllers/Dean/OfferingController';
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
import { Label } from '@/components/ui/label';
import type { Offering, Subject, TeacherOption } from '@/types';

/**
 * Add a subject to the section, or (with `offering`) change its default
 * teacher, optionally moving its existing classes too.
 */
const props = defineProps<{
    sectionId: number;
    teachers: TeacherOption[];
    subjects?: Pick<Subject, 'id' | 'code' | 'title'>[];
    offering?: Offering;
}>();

const open = ref(false);
const form = useForm({
    subject_id: null as number | null,
    teacher_id: props.offering?.teacher_id ?? (null as number | null),
    apply_to_classes: false,
});

const conflicts = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('teacher_id.'))
        .map(([, message]) => message),
);

function submit(): void {
    form.transform((data) =>
        props.offering
            ? {
                  teacher_id: data.teacher_id,
                  apply_to_classes: data.apply_to_classes,
              }
            : { subject_id: data.subject_id, teacher_id: data.teacher_id },
    ).submit(
        props.offering
            ? OfferingController.update(props.offering.id)
            : OfferingController.store(props.sectionId),
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;

                if (!props.offering) {
                    form.reset();
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
            <DialogTitle>{{
                offering
                    ? `Teacher for ${offering.subject.code}`
                    : 'Add subject'
            }}</DialogTitle>
            <DialogDescription>
                {{
                    offering
                        ? 'The default teacher is filled in for new classes.'
                        : "Only your college's subjects not yet in this section."
                }}
            </DialogDescription>
            <form class="space-y-4" @submit.prevent="submit">
                <div v-if="!offering" class="grid gap-2">
                    <Label for="offering-subject">Subject</Label>
                    <select
                        id="offering-subject"
                        v-model="form.subject_id"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                    >
                        <option :value="null" disabled>Choose…</option>
                        <option
                            v-for="subject in subjects"
                            :key="subject.id"
                            :value="subject.id"
                        >
                            {{ subject.code }} · {{ subject.title }}
                        </option>
                    </select>
                    <InputError :message="form.errors.subject_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="offering-teacher">Default teacher</Label>
                    <select
                        id="offering-teacher"
                        v-model="form.teacher_id"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                    >
                        <option :value="null">To be announced</option>
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
                <Label v-if="offering" class="flex items-start gap-2">
                    <Checkbox v-model="form.apply_to_classes" class="mt-0.5" />
                    <span>
                        Also move its classes to this teacher
                        <span
                            class="block text-xs font-normal text-muted-foreground"
                        >
                            For a substitute or a reassignment. Each class is
                            checked against the teacher's other classes.
                        </span>
                    </span>
                </Label>
                <ul
                    v-if="conflicts.length"
                    class="space-y-1 text-sm text-destructive"
                >
                    <li v-for="conflict in conflicts" :key="conflict">
                        {{ conflict }}
                    </li>
                </ul>
                <DialogFooter>
                    <Button :disabled="form.processing">Save</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

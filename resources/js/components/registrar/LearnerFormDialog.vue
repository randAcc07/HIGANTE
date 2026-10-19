<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import LearnerController from '@/actions/App/Http/Controllers/Registrar/LearnerController';
import InputError from '@/components/InputError.vue';
import NameFields from '@/components/NameFields.vue';
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
import type { College, LearnerRecord, LearnerStatus, Program } from '@/types';

const props = defineProps<{
    colleges: (Pick<College, 'id' | 'code' | 'name'> & {
        programs: Pick<Program, 'id' | 'college_id' | 'code' | 'name'>[];
    })[];
    learner?: LearnerRecord;
}>();

const open = ref(false);
const form = useForm({
    first_name: props.learner?.user.first_name ?? '',
    middle_name: props.learner?.user.middle_name ?? '',
    last_name: props.learner?.user.last_name ?? '',
    suffix: props.learner?.user.suffix ?? '',
    email: props.learner?.user.email ?? '',
    student_number: props.learner?.student_number ?? '',
    program_id: props.learner?.program_id ?? null,
    year_level: String(props.learner?.year_level ?? 1),
    status: (props.learner?.status ?? 'active') as LearnerStatus,
});

function submit(): void {
    form.transform((data) => ({
        first_name: data.first_name,
        middle_name: data.middle_name || null,
        last_name: data.last_name,
        suffix: data.suffix || null,
        ...(props.learner ? {} : { email: data.email }),
        student_number: data.student_number,
        program_id: data.program_id,
        year_level: Number(data.year_level),
        status: data.status,
    })).submit(
        props.learner
            ? LearnerController.update(props.learner.id)
            : LearnerController.store(),
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;

                if (!props.learner) {
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
        <DialogContent class="max-h-[90vh] overflow-y-auto">
            <DialogTitle>{{
                learner ? `Edit ${learner.user.full_name}` : 'Admit a learner'
            }}</DialogTitle>
            <DialogDescription>
                {{
                    learner
                        ? 'The email changes through Settings, or the admin for a lost inbox.'
                        : 'They get a link to set their own password. Register them for a term separately.'
                }}
            </DialogDescription>
            <form class="space-y-4" @submit.prevent="submit">
                <NameFields
                    v-model:first-name="form.first_name"
                    v-model:middle-name="form.middle_name"
                    v-model:last-name="form.last_name"
                    v-model:suffix="form.suffix"
                    :errors="form.errors"
                    id-prefix="learner"
                />

                <div v-if="!learner" class="grid gap-2">
                    <Label for="learner-email">Email</Label>
                    <Input
                        id="learner-email"
                        v-model="form.email"
                        type="email"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="learner-number">Student number</Label>
                        <Input
                            id="learner-number"
                            v-model="form.student_number"
                            placeholder="M2026-04821"
                        />
                        <InputError :message="form.errors.student_number" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="learner-year">Year level</Label>
                        <Input
                            id="learner-year"
                            v-model="form.year_level"
                            type="number"
                            min="1"
                            max="6"
                        />
                        <InputError :message="form.errors.year_level" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="learner-program">Program</Label>
                    <select
                        id="learner-program"
                        v-model="form.program_id"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                    >
                        <option :value="null" disabled>Choose…</option>
                        <optgroup
                            v-for="college in colleges"
                            :key="college.id"
                            :label="college.name"
                        >
                            <option
                                v-for="program in college.programs"
                                :key="program.id"
                                :value="program.id"
                            >
                                {{ program.code }} · {{ program.name }}
                            </option>
                        </optgroup>
                    </select>
                    <InputError :message="form.errors.program_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="learner-status">Student status</Label>
                    <select
                        id="learner-status"
                        v-model="form.status"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                    >
                        <option value="active">Active</option>
                        <option value="inactive">Inactive (on leave)</option>
                        <option value="graduated">Graduated</option>
                        <option value="dropped_out">Dropped out</option>
                    </select>
                    <InputError :message="form.errors.status" />
                </div>

                <DialogFooter>
                    <Button :disabled="form.processing">Save</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

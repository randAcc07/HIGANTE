<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import ProgramController from '@/actions/App/Http/Controllers/Admin/ProgramController';
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
import type { Program } from '@/types';

const props = defineProps<{
    collegeId: number;
    program?: Program;
}>();

const open = ref(false);
const form = useForm({
    code: props.program?.code ?? '',
    name: props.program?.name ?? '',
});

function submit(): void {
    form.submit(
        props.program
            ? ProgramController.update(props.program.id)
            : ProgramController.store(props.collegeId),
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;

                if (!props.program) {
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
            <DialogHeader>
                <DialogTitle>{{
                    program ? `Edit ${program.code}` : 'Add program'
                }}</DialogTitle>
                <DialogDescription>
                    A degree program, what the school calls a course.
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="program-code">Code</Label>
                    <Input
                        id="program-code"
                        v-model="form.code"
                        placeholder="BSCS"
                    />
                    <InputError :message="form.errors.code" />
                </div>
                <div class="grid gap-2">
                    <Label for="program-name">Name</Label>
                    <Input
                        id="program-name"
                        v-model="form.name"
                        placeholder="Bachelor of Science in Computer Science"
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <DialogFooter>
                    <Button :disabled="form.processing">Save</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

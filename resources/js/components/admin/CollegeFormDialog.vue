<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import CollegeController from '@/actions/App/Http/Controllers/Admin/CollegeController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { College } from '@/types';

const props = defineProps<{
    college?: College;
}>();

const open = ref(false);
const form = useForm({
    code: props.college?.code ?? '',
    name: props.college?.name ?? '',
});

function submit(): void {
    form.submit(
        props.college
            ? CollegeController.update(props.college.id)
            : CollegeController.store(),
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;

                if (!props.college) {
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
                college ? `Edit ${college.code}` : 'Add college'
            }}</DialogTitle>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="college-code">Code</Label>
                    <Input
                        id="college-code"
                        v-model="form.code"
                        placeholder="COS"
                    />
                    <InputError :message="form.errors.code" />
                </div>
                <div class="grid gap-2">
                    <Label for="college-name">Name</Label>
                    <Input
                        id="college-name"
                        v-model="form.name"
                        placeholder="College of Science"
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

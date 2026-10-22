<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import BatchController from '@/actions/App/Http/Controllers/Dean/BatchController';
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
import type { Batch } from '@/types';

const props = defineProps<{
    offeringId: number;
    subjectCode: string;
    suggestedName?: string;
    batch?: Batch;
}>();

const open = ref(false);
const form = useForm({ name: props.batch?.name ?? props.suggestedName ?? '' });

function submit(): void {
    form.submit(
        props.batch
            ? BatchController.update(props.batch.id)
            : BatchController.store(props.offeringId),
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
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
                    batch
                        ? `Rename ${batch.name}`
                        : `Add a batch to ${subjectCode}`
                }}</DialogTitle>
                <DialogDescription>
                    A split of the subject's learners, e.g. for a lab. Learners
                    are put into batches when they are enrolled.
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="batch-name">Name</Label>
                    <Input id="batch-name" v-model="form.name" />
                    <InputError :message="form.errors.name" />
                </div>
                <DialogFooter>
                    <Button :disabled="form.processing">Save</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

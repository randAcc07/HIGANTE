<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

/** A decision with remarks for the learner (required unless optional). */
const props = withDefaults(
    defineProps<{
        title: string;
        description: string;
        confirmLabel: string;
        url: string;
        remarksRequired?: boolean;
        destructive?: boolean;
    }>(),
    { remarksRequired: false, destructive: false },
);

const open = ref(false);
const form = useForm({ remarks: '' });

function submit(): void {
    form.transform((data) => ({ remarks: data.remarks || null })).post(
        props.url,
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
                form.reset();
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
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="decision-remarks">{{
                        remarksRequired
                            ? 'Remarks for the learner'
                            : 'Remarks (optional)'
                    }}</Label>
                    <textarea
                        id="decision-remarks"
                        v-model="form.remarks"
                        rows="3"
                        class="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                    />
                    <InputError :message="form.errors.remarks" />
                    <InputError
                        :message="
                            (form.errors as Record<string, string>).excuse
                        "
                    />
                </div>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary"
                            >Cancel</Button
                        >
                    </DialogClose>
                    <Button
                        :variant="destructive ? 'destructive' : 'default'"
                        :disabled="
                            form.processing ||
                            (remarksRequired && !form.remarks.trim())
                        "
                        >{{ confirmLabel }}</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

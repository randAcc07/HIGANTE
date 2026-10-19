<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import CalendarController from '@/actions/App/Http/Controllers/CalendarController';
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
import type { CalendarEvent } from '@/types';

const props = defineProps<{
    event?: CalendarEvent;
    /** College code for a dean's events; null for the admin's campus-wide ones. */
    scope: string | null;
}>();

const open = ref(false);
const form = useForm({
    title: props.event?.title ?? '',
    description: props.event?.description ?? '',
    starts_on: props.event?.starts_on ?? '',
    ends_on: props.event?.ends_on ?? '',
    suspends_classes: props.event?.suspends_classes ?? true,
});

function submit(): void {
    form.transform((data) => ({
        ...data,
        description: data.description || null,
        ends_on: data.ends_on || data.starts_on,
    })).submit(
        props.event
            ? CalendarController.update(props.event.id)
            : CalendarController.store(),
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;

                if (!props.event) {
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
            <DialogTitle>{{ event ? 'Edit event' : 'Add event' }}</DialogTitle>
            <DialogDescription>
                {{
                    (event?.college ?? scope)
                        ? `For ${event?.college ?? scope} only.`
                        : 'For the whole campus.'
                }}
            </DialogDescription>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="event-title">Title</Label>
                    <Input
                        id="event-title"
                        v-model="form.title"
                        placeholder="Bonifacio Day"
                    />
                    <InputError :message="form.errors.title" />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="event-start">From</Label>
                        <Input
                            id="event-start"
                            v-model="form.starts_on"
                            type="date"
                        />
                        <InputError :message="form.errors.starts_on" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="event-end">To</Label>
                        <Input
                            id="event-end"
                            v-model="form.ends_on"
                            type="date"
                            :min="form.starts_on"
                        />
                        <InputError :message="form.errors.ends_on" />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="event-description">Details</Label>
                    <textarea
                        id="event-description"
                        v-model="form.description"
                        rows="3"
                        class="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                        placeholder="Optional"
                    />
                    <InputError :message="form.errors.description" />
                </div>
                <Label class="flex items-start gap-2">
                    <Checkbox v-model="form.suspends_classes" class="mt-0.5" />
                    <span>
                        No classes on these days
                        <span
                            class="block text-xs font-normal text-muted-foreground"
                        >
                            Days without classes aren't counted when tracking
                            attendance.
                        </span>
                    </span>
                </Label>
                <DialogFooter>
                    <Button :disabled="form.processing">Save</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

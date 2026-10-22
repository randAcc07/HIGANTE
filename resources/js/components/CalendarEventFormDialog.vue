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
    DialogHeader,
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
    /** Programs a suspension can be narrowed to, by college. */
    programOptions: {
        id: number;
        code: string;
        programs: { id: number; code: string; name: string }[];
    }[];
}>();

const open = ref(false);
const form = useForm({
    title: props.event?.title ?? '',
    description: props.event?.description ?? '',
    starts_on: props.event?.starts_on ?? '',
    ends_on: props.event?.ends_on ?? '',
    suspends_classes: props.event?.suspends_classes ?? true,
    suspends_from: props.event?.suspends_from ?? '',
    suspends_until: props.event?.suspends_until ?? '',
    program_ids: [...(props.event?.program_ids ?? [])] as number[],
    year_levels: [...(props.event?.year_levels ?? [])] as number[],
    memo_number: props.event?.memo_number ?? '',
    memo: null as File | null,
    remove_memo: false,
});

function toggle(
    list: 'program_ids' | 'year_levels',
    id: number,
    on: boolean | 'indeterminate',
): void {
    form[list] =
        on === true
            ? [...form[list], id]
            : form[list].filter((value) => value !== id);
}

function pickMemo(event: Event): void {
    form.memo = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submit(): void {
    // A file can't travel in a PUT that PHP reads, so edits are POSTed with
    // the method spoofed.
    form.transform((data) => ({
        ...data,
        description: data.description || null,
        ends_on: data.ends_on || data.starts_on,
        suspends_from: data.suspends_classes
            ? data.suspends_from || null
            : null,
        suspends_until: data.suspends_classes
            ? data.suspends_until || null
            : null,
        program_ids: data.suspends_classes ? data.program_ids : [],
        year_levels: data.suspends_classes ? data.year_levels : [],
        memo_number: data.memo_number || null,
        ...(props.event ? { _method: 'put' } : {}),
    })).post(
        props.event
            ? CalendarController.update.url(props.event.id)
            : CalendarController.store.url(),
        {
            forceFormData: true,
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
            <DialogHeader>
                <DialogTitle>{{
                    event ? 'Edit event' : 'Add event'
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        (event?.college ?? scope)
                            ? `For ${event?.college ?? scope} only.`
                            : 'For the whole campus.'
                    }}
                </DialogDescription>
            </DialogHeader>
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
                <div
                    v-if="form.suspends_classes"
                    class="grid gap-2 rounded-lg border p-3"
                >
                    <span class="text-sm font-medium">Classes affected</span>
                    <span class="text-xs text-muted-foreground"
                        >Leave both empty for whole days. Otherwise, classes
                        that start from the first time and before the second are
                        suspended (e.g. from 1:00 PM).</span
                    >
                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label for="event-from">Starting from</Label>
                            <Input
                                id="event-from"
                                v-model="form.suspends_from"
                                type="time"
                                step="1800"
                            />
                            <InputError :message="form.errors.suspends_from" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="event-until">Starting before</Label>
                            <Input
                                id="event-until"
                                v-model="form.suspends_until"
                                type="time"
                                step="1800"
                            />
                            <InputError :message="form.errors.suspends_until" />
                        </div>
                    </div>
                    <span class="mt-2 text-sm font-medium"
                        >Only these year levels</span
                    >
                    <span class="text-xs text-muted-foreground"
                        >None ticked: every year level. By the class's section,
                        so an irregular learner in another year's class still
                        has it.</span
                    >
                    <div class="flex flex-wrap gap-3">
                        <Label
                            v-for="year in 6"
                            :key="year"
                            class="flex items-center gap-1 font-normal"
                        >
                            <Checkbox
                                :model-value="form.year_levels.includes(year)"
                                @update:model-value="
                                    toggle('year_levels', year, $event)
                                "
                            />
                            Year {{ year }}
                        </Label>
                    </div>
                    <InputError :message="form.errors.year_levels" />
                    <span class="mt-2 text-sm font-medium"
                        >Only these programs</span
                    >
                    <span class="text-xs text-muted-foreground"
                        >None ticked: every program{{
                            scope ? ` of ${scope}` : ' on campus'
                        }}.</span
                    >
                    <div
                        v-for="college in programOptions"
                        :key="college.id"
                        class="space-y-1"
                    >
                        <span
                            v-if="programOptions.length > 1"
                            class="text-xs text-muted-foreground"
                            >{{ college.code }}</span
                        >
                        <div class="flex flex-wrap gap-3">
                            <Label
                                v-for="program in college.programs"
                                :key="program.id"
                                class="flex items-center gap-1 font-normal"
                                :title="program.name"
                            >
                                <Checkbox
                                    :model-value="
                                        form.program_ids.includes(program.id)
                                    "
                                    @update:model-value="
                                        toggle(
                                            'program_ids',
                                            program.id,
                                            $event,
                                        )
                                    "
                                />
                                {{ program.code }}
                            </Label>
                        </div>
                    </div>
                    <InputError :message="form.errors.program_ids" />
                    <InputError
                        v-for="(error, key) in form.errors"
                        v-show="String(key).startsWith('program_ids.')"
                        :key="key"
                        :message="error"
                    />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="event-memo-number">Memo number</Label>
                        <Input
                            id="event-memo-number"
                            v-model="form.memo_number"
                            placeholder="Memo No. 42, s. 2026"
                        />
                        <InputError :message="form.errors.memo_number" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="event-memo">Memo (PDF)</Label>
                        <Input
                            id="event-memo"
                            type="file"
                            accept="application/pdf"
                            @change="pickMemo"
                        />
                        <InputError :message="form.errors.memo" />
                    </div>
                </div>
                <Label
                    v-if="event?.memo_url && !form.memo"
                    class="flex items-center gap-2 text-sm font-normal"
                >
                    <Checkbox v-model="form.remove_memo" />
                    Remove the attached memo ({{ event.memo_name }})
                </Label>
                <DialogFooter>
                    <Button :disabled="form.processing">Save</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

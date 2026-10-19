<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import ScheduleController from '@/actions/App/Http/Controllers/Dean/ScheduleController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { formatTime, fromMinutes, toMinutes, WEEKDAYS } from '@/lib/time';
import type {
    ClassBlock,
    Offering,
    RoomOption,
    TeacherOption,
    Weekday,
} from '@/types';

/**
 * Add a class from an empty grid slot, or edit or remove one. Conflicts come
 * back from the server as slot.0, slot.1, ... and are all listed.
 */
const props = defineProps<{
    offerings: Offering[];
    teachers: TeacherOption[];
    rooms: RoomOption[];
    /** An existing class to edit, or a slot to start a new one in. */
    target: ClassBlock | { weekday: Weekday; start: string } | null;
    editable: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

const TIMES = Array.from({ length: 27 }, (_, i) =>
    fromMinutes(7 * 60 + i * 30),
);

const existing = computed(() =>
    props.target && 'id' in props.target ? props.target : null,
);

const form = useForm({
    subject_offering_id: null as number | null,
    batch_id: null as number | null,
    weekday: 1 as Weekday,
    start: '07:00',
    end: '08:30',
    mode: 'onsite' as 'onsite' | 'online',
    room_id: null as number | null,
    teacher_id: null as number | null,
});

watch(open, (isOpen) => {
    if (!isOpen || !props.target) {
        return;
    }

    form.clearErrors();
    const target = props.target;

    if ('id' in target) {
        form.defaults({
            subject_offering_id: target.subject_offering_id,
            batch_id: target.batch_id,
            weekday: target.weekday,
            start: target.start,
            end: target.end,
            mode: target.mode,
            room_id: target.room_id,
            teacher_id: target.teacher_id,
        });
    } else {
        const first = props.offerings[0];
        form.defaults({
            subject_offering_id: first?.id ?? null,
            batch_id: null,
            weekday: target.weekday,
            start: target.start,
            end: fromMinutes(Math.min(toMinutes(target.start) + 90, 20 * 60)),
            mode: 'onsite',
            room_id: null,
            teacher_id: first?.teacher_id ?? null,
        });
    }

    form.reset();
});

const offering = computed(
    () =>
        props.offerings.find((item) => item.id === form.subject_offering_id) ??
        null,
);

// A new class takes the subject's default teacher.
watch(
    () => form.subject_offering_id,
    () => {
        if (!existing.value) {
            form.batch_id = null;
            form.teacher_id = offering.value?.teacher_id ?? form.teacher_id;
        }
    },
);

const endTimes = computed(() =>
    TIMES.filter((time) => toMinutes(time) > toMinutes(form.start)),
);

const buildings = computed(() => {
    const groups = new Map<string, RoomOption[]>();

    for (const room of props.rooms) {
        const building = room.building ?? 'Other';
        groups.set(building, [...(groups.get(building) ?? []), room]);
    }

    return [...groups.entries()];
});

const conflicts = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('slot'))
        .map(([, message]) => message),
);

const locked = computed(() => existing.value?.has_attendance === true);

function submit(): void {
    form.transform((data) => ({
        batch_id: data.batch_id,
        weekday: data.weekday,
        start: data.start,
        end: data.end,
        mode: data.mode,
        room_id: data.mode === 'online' ? null : data.room_id,
        teacher_id: data.teacher_id,
    })).submit(
        existing.value
            ? ScheduleController.update(existing.value.id)
            : ScheduleController.store(form.subject_offering_id ?? 0),
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
            },
        },
    );
}

function remove(): void {
    if (existing.value) {
        router.delete(ScheduleController.destroy.url(existing.value.id), {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
            },
        });
    }
}

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm disabled:opacity-60';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto">
            <DialogTitle>{{
                existing
                    ? `${existing.subject}${existing.batch ? ` (${existing.batch})` : ''}`
                    : 'Add a class'
            }}</DialogTitle>
            <DialogDescription v-if="existing">
                {{ WEEKDAYS[existing.weekday] }},
                {{ formatTime(existing.start) }}–{{
                    formatTime(existing.end)
                }}
                · {{ existing.room ?? 'Online' }} · {{ existing.teacher }}
            </DialogDescription>
            <DialogDescription v-else
                >A weekly class of one of the section's
                subjects.</DialogDescription
            >

            <ul
                v-if="existing?.warnings?.length"
                class="space-y-1 rounded-md bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100"
            >
                <li v-for="warning in existing.warnings" :key="warning">
                    {{ warning }}
                </li>
            </ul>

            <form v-if="editable" class="space-y-4" @submit.prevent="submit">
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="class-subject">Subject</Label>
                        <select
                            id="class-subject"
                            v-model="form.subject_offering_id"
                            :class="selectClass"
                            :disabled="!!existing"
                        >
                            <option
                                v-for="item in offerings"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.subject.code }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="class-batch">Who</Label>
                        <select
                            id="class-batch"
                            v-model="form.batch_id"
                            :class="selectClass"
                        >
                            <option :value="null">Whole subject</option>
                            <option
                                v-for="batch in offering?.batches ?? []"
                                :key="batch.id"
                                :value="batch.id"
                            >
                                Batch {{ batch.name }}
                            </option>
                        </select>
                        <InputError :message="form.errors.batch_id" />
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div class="grid gap-2">
                        <Label for="class-day">Day</Label>
                        <select
                            id="class-day"
                            v-model="form.weekday"
                            :class="selectClass"
                            :disabled="locked"
                        >
                            <option
                                v-for="day in [1, 2, 3, 4, 5, 6]"
                                :key="day"
                                :value="day"
                            >
                                {{ WEEKDAYS[day] }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="class-start">From</Label>
                        <select
                            id="class-start"
                            v-model="form.start"
                            :class="selectClass"
                            :disabled="locked"
                        >
                            <option
                                v-for="time in TIMES.slice(0, -1)"
                                :key="time"
                                :value="time"
                            >
                                {{ formatTime(time) }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="class-end">To</Label>
                        <select
                            id="class-end"
                            v-model="form.end"
                            :class="selectClass"
                            :disabled="locked"
                        >
                            <option
                                v-for="time in endTimes"
                                :key="time"
                                :value="time"
                            >
                                {{ formatTime(time) }}
                            </option>
                        </select>
                    </div>
                </div>
                <InputError
                    :message="
                        form.errors.start ??
                        form.errors.end ??
                        form.errors.weekday
                    "
                />
                <p v-if="locked" class="text-xs text-muted-foreground">
                    This class already has attendance, so its day and time stay.
                </p>

                <div class="grid grid-cols-3 gap-4">
                    <div class="grid gap-2">
                        <Label for="class-mode">Where</Label>
                        <select
                            id="class-mode"
                            v-model="form.mode"
                            :class="selectClass"
                        >
                            <option value="onsite">In a room</option>
                            <option value="online">Online</option>
                        </select>
                    </div>
                    <div
                        v-if="form.mode === 'onsite'"
                        class="col-span-2 grid gap-2"
                    >
                        <Label for="class-room">Room</Label>
                        <select
                            id="class-room"
                            v-model="form.room_id"
                            :class="selectClass"
                        >
                            <option :value="null" disabled>Choose…</option>
                            <optgroup
                                v-for="[building, buildingRooms] in buildings"
                                :key="building"
                                :label="building"
                            >
                                <option
                                    v-for="room in buildingRooms"
                                    :key="room.id"
                                    :value="room.id"
                                >
                                    {{ room.name ?? room.code
                                    }}{{
                                        room.capacity
                                            ? ` · ${room.capacity} seats`
                                            : ''
                                    }}
                                </option>
                            </optgroup>
                        </select>
                        <InputError :message="form.errors.room_id" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="class-teacher">Teacher</Label>
                    <select
                        id="class-teacher"
                        v-model="form.teacher_id"
                        :class="selectClass"
                    >
                        <option :value="null" disabled>Choose…</option>
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

                <ul
                    v-if="conflicts.length"
                    class="space-y-1 rounded-md bg-destructive/10 p-3 text-sm text-destructive"
                    data-test="schedule-conflicts"
                >
                    <li v-for="conflict in conflicts" :key="conflict">
                        {{ conflict }}
                    </li>
                </ul>

                <DialogFooter class="gap-2 sm:justify-between">
                    <Button
                        v-if="existing && !locked"
                        type="button"
                        variant="ghost"
                        class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                        @click="remove"
                        >Remove class</Button
                    >
                    <span v-else />
                    <Button :disabled="form.processing">Save</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

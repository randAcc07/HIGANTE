<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import RoomController from '@/actions/App/Http/Controllers/Admin/RoomController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
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
import type { Room, RoomType } from '@/types';

const props = defineProps<{
    buildings: string[];
    room?: Room;
}>();

const open = ref(false);
const form = useForm({
    code: props.room?.code ?? '',
    building: props.room?.building ?? '',
    // Text in the form; empty means "not set".
    floor: props.room?.floor?.toString() ?? '',
    capacity: props.room?.capacity?.toString() ?? '',
    room_type: (props.room?.room_type ?? 'lecture') as RoomType,
    is_active: props.room?.is_active ?? true,
});

function submit(): void {
    form.transform((data) => ({
        ...data,
        building: data.building || null,
        floor: data.floor === '' ? null : Number(data.floor),
        capacity: data.capacity === '' ? null : Number(data.capacity),
    })).submit(
        props.room
            ? RoomController.update(props.room.id)
            : RoomController.store(),
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;

                if (!props.room) {
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
                room ? `Edit ${room.code}` : 'Add room'
            }}</DialogTitle>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="room-code">Code</Label>
                        <Input
                            id="room-code"
                            v-model="form.code"
                            placeholder="MB-101"
                        />
                        <InputError :message="form.errors.code" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="room-type">Type</Label>
                        <Select v-model="form.room_type">
                            <SelectTrigger id="room-type" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="lecture">Lecture</SelectItem>
                                <SelectItem value="laboratory"
                                    >Laboratory</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.room_type" />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="room-building">Building</Label>
                    <Input
                        id="room-building"
                        v-model="form.building"
                        list="room-buildings"
                        placeholder="Main Building"
                    />
                    <datalist id="room-buildings">
                        <option
                            v-for="building in buildings"
                            :key="building"
                            :value="building"
                        />
                    </datalist>
                    <InputError :message="form.errors.building" />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="room-floor">Floor</Label>
                        <Input
                            id="room-floor"
                            v-model="form.floor"
                            type="number"
                        />
                        <InputError :message="form.errors.floor" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="room-capacity">Capacity</Label>
                        <Input
                            id="room-capacity"
                            v-model="form.capacity"
                            type="number"
                            min="1"
                            placeholder="Unknown"
                        />
                        <InputError :message="form.errors.capacity" />
                    </div>
                </div>
                <p class="text-xs text-muted-foreground">
                    Capacity is optional. It only warns when a class won't fit;
                    it never blocks a booking.
                </p>
                <Label class="flex items-center gap-2">
                    <Checkbox v-model="form.is_active" />
                    <span>Active (bookable)</span>
                </Label>
                <DialogFooter>
                    <Button :disabled="form.processing">Save</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

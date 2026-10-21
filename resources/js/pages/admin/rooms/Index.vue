<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import RoomController from '@/actions/App/Http/Controllers/Admin/RoomController';
import RoomFormDialog from '@/components/admin/RoomFormDialog.vue';
import ConfirmDeleteDialog from '@/components/ConfirmDeleteDialog.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useFilters } from '@/composables/useFilters';
import type { Paginated, Room } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Rooms', href: RoomController.index() }],
    },
});

const props = defineProps<{
    rooms: Paginated<Room>;
    buildings: string[];
    filters: {
        search: string | null;
        type: string | null;
        status: string | null;
    };
}>();

const filters = useFilters(RoomController.index.url(), {
    search: props.filters.search ?? '',
    type: props.filters.type,
    status: props.filters.status,
});
</script>

<template>
    <Head title="Rooms" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Rooms"
                description="Shared by the whole campus; any dean can book any active room"
            />
            <RoomFormDialog :buildings="buildings">
                <Button><Plus class="h-4 w-4" /> Add room</Button>
            </RoomFormDialog>
        </div>

        <div class="flex flex-wrap gap-3">
            <Input
                v-model="filters.search"
                class="max-w-xs"
                placeholder="Search name, code or building"
                aria-label="Search rooms"
            />
            <NativeSelect v-model="filters.type" aria-label="Type">
                <option :value="null">All types</option>
                <option value="lecture">Lecture</option>
                <option value="laboratory">Laboratory</option>
                <option value="venue">Venue</option>
            </NativeSelect>
            <NativeSelect v-model="filters.status" aria-label="Status">
                <option :value="null">Active and inactive</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </NativeSelect>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Room</th>
                        <th class="px-4 py-2 font-medium">Building</th>
                        <th class="px-4 py-2 text-right font-medium">Floor</th>
                        <th class="px-4 py-2 font-medium">Type</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Capacity
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Weekly classes
                        </th>
                        <th class="px-4 py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="room in rooms.data"
                        :key="room.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2">
                            <div class="font-medium whitespace-nowrap">
                                {{ room.label }}
                                <Badge
                                    v-if="!room.is_active"
                                    variant="secondary"
                                    class="ml-2"
                                    >Inactive</Badge
                                >
                            </div>
                            <div
                                v-if="room.name"
                                class="text-xs text-muted-foreground"
                            >
                                {{ room.code }}
                            </div>
                        </td>
                        <td class="px-4 py-2">{{ room.building ?? '—' }}</td>
                        <td class="px-4 py-2 text-right">
                            {{ room.floor ?? '—' }}
                        </td>
                        <td class="px-4 py-2 capitalize">
                            {{ room.room_type }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            {{ room.capacity ?? '—' }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            {{ room.schedules_count }}
                        </td>
                        <td class="px-4 py-2">
                            <div class="flex justify-end gap-1">
                                <RoomFormDialog
                                    :buildings="buildings"
                                    :room="room"
                                >
                                    <Button variant="ghost" size="sm">
                                        <Pencil class="h-4 w-4" />
                                        <span class="sr-only">Edit</span>
                                    </Button>
                                </RoomFormDialog>
                                <ConfirmDeleteDialog
                                    :title="`Delete ${room.label}?`"
                                    description="Only a room with no classes can be deleted. Deactivate a room in use instead."
                                    :url="RoomController.destroy.url(room.id)"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="rooms.data.length === 0">
                        <td
                            colspan="7"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No rooms match.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="rooms" />
    </div>
</template>

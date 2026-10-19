<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import SectionController from '@/actions/App/Http/Controllers/Dean/SectionController';
import TimetableController from '@/actions/App/Http/Controllers/Dean/TimetableController';
import Heading from '@/components/Heading.vue';
import ScheduleGrid from '@/components/ScheduleGrid.vue';
import type {
    BusyBlock,
    ClassBlock,
    GridBlock,
    Room,
    TeacherOption,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Timetables', href: TimetableController.index() },
        ],
    },
});

const props = defineProps<{
    terms: { id: number; label: string }[];
    term: { id: number; label: string } | null;
    rooms: Pick<Room, 'id' | 'code' | 'name' | 'building'>[];
    teachers: TeacherOption[];
    selected: { room: number | null; teacher: number | null };
    classes: (ClassBlock | BusyBlock)[];
}>();

const blocks = computed<GridBlock[]>(() => {
    const sections = new Map<string, number>();

    return props.classes.map((item) => {
        if ('busy' in item) {
            return {
                key: `busy-${item.id}`,
                id: item.id,
                weekday: item.weekday,
                start: item.start,
                end: item.end,
                title: 'Booked',
                lines: [item.busy],
                tone: 'busy',
            };
        }

        if (!sections.has(item.section)) {
            sections.set(item.section, sections.size);
        }

        return {
            key: `class-${item.id}`,
            id: item.id,
            weekday: item.weekday,
            start: item.start,
            end: item.end,
            title: item.batch
                ? `${item.subject} · ${item.batch}`
                : item.subject,
            lines: [
                item.section,
                props.selected.room ? item.teacher : (item.room ?? 'Online'),
            ],
            tone: sections.get(item.section) ?? 0,
        };
    });
});

function show(query: Record<string, number | null | undefined>): void {
    router.get(
        TimetableController.index.url(),
        Object.fromEntries(
            Object.entries({ term: props.term?.id, ...query }).filter(
                ([, value]) => value != null,
            ),
        ),
        { preserveScroll: true },
    );
}

function openSection(block: GridBlock): void {
    const item = props.classes.find((entry) => entry.id === block.id);

    if (item && 'section_id' in item && item.section_id) {
        router.visit(SectionController.show.url(item.section_id));
    }
}
</script>

<template>
    <Head title="Timetables" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Timetables"
            :description="`A room's or a teacher's week${term ? ` · ${term.label}` : ''}. Other colleges' bookings of a room show only as booked.`"
        />

        <div class="flex flex-wrap gap-3">
            <select
                v-if="terms.length > 1"
                :value="term?.id"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                aria-label="Term"
                @change="
                    show({
                        term: Number(
                            ($event.target as HTMLSelectElement).value,
                        ),
                        room: selected.room,
                        teacher: selected.teacher,
                    })
                "
            >
                <option
                    v-for="option in terms"
                    :key="option.id"
                    :value="option.id"
                >
                    {{ option.label }}
                </option>
            </select>
            <select
                :value="selected.room ?? ''"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                aria-label="Room"
                @change="
                    show({
                        room:
                            Number(
                                ($event.target as HTMLSelectElement).value,
                            ) || null,
                    })
                "
            >
                <option value="">Choose a room…</option>
                <option v-for="room in rooms" :key="room.id" :value="room.id">
                    {{ room.name ?? room.code }} · {{ room.building }}
                </option>
            </select>
            <select
                :value="selected.teacher ?? ''"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                aria-label="Teacher"
                @change="
                    show({
                        teacher:
                            Number(
                                ($event.target as HTMLSelectElement).value,
                            ) || null,
                    })
                "
            >
                <option value="">Choose a teacher…</option>
                <option
                    v-for="teacher in teachers"
                    :key="teacher.id"
                    :value="teacher.id"
                >
                    {{ teacher.name }}
                </option>
            </select>
        </div>

        <ScheduleGrid
            v-if="selected.room || selected.teacher"
            :blocks="blocks"
            @block="openSection"
        />
        <p
            v-else
            class="rounded-lg border p-8 text-center text-sm text-muted-foreground"
        >
            Choose a room or a teacher to see their week.
        </p>
    </div>
</template>

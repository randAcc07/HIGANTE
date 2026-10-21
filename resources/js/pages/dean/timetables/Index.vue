<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import TimetableController from '@/actions/App/Http/Controllers/Dean/TimetableController';
import ScheduleFormDialog from '@/components/dean/ScheduleFormDialog.vue';
import GridZoomControl from '@/components/GridZoomControl.vue';
import Heading from '@/components/Heading.vue';
import NextTermButton from '@/components/NextTermButton.vue';
import ScheduleGrid from '@/components/ScheduleGrid.vue';
import { useGridZoom } from '@/composables/useGridZoom';
import type {
    BusyBlock,
    ClassBlock,
    GridBlock,
    NewClassSlot,
    Offering,
    RoomOption,
    TeacherOption,
    Weekday,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Timetables', href: TimetableController.index() },
        ],
    },
});

type View = 'section' | 'room' | 'teacher';

const props = defineProps<{
    terms: { id: number; label: string }[];
    term: { id: number; label: string } | null;
    sections: { id: number; label: string }[];
    rooms: RoomOption[];
    teachers: TeacherOption[];
    batches: string[];
    offerings: Offering[];
    selected: { view: View; target: number | null; batch: string | null };
    classes: (ClassBlock | BusyBlock)[];
    canEdit: boolean;
    /** The next term, while the page shows another: a shortcut to it. */
    nextTerm: { id: number; label: string } | null;
}>();

const VIEWS: { value: View; label: string }[] = [
    { value: 'section', label: 'Section' },
    { value: 'room', label: 'Room' },
    { value: 'teacher', label: 'Teacher' },
];

const targets = computed(() => {
    if (props.selected.view === 'section') {
        return props.sections;
    }

    if (props.selected.view === 'room') {
        return props.rooms.map((room) => ({
            id: room.id,
            label: `${room.name ?? room.code} · ${room.building ?? ''}`,
        }));
    }

    return props.teachers.map((teacher) => ({
        id: teacher.id,
        label: teacher.name,
    }));
});

const blocks = computed<GridBlock[]>(() => {
    const tones = new Map<string, number>();

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

        // Colour by subject in a section's week, by section elsewhere.
        const group =
            props.selected.view === 'section' ? item.subject : item.section;

        if (!tones.has(group)) {
            tones.set(group, tones.size);
        }

        const where = item.room ?? 'Online';
        const lines =
            props.selected.view === 'section'
                ? [where, item.teacher]
                : props.selected.view === 'room'
                  ? [item.section, item.teacher]
                  : [item.section, where];

        return {
            key: `class-${item.id}`,
            id: item.id,
            weekday: item.weekday,
            start: item.start,
            end: item.end,
            title: item.batch
                ? `${item.subject} · ${item.batch}`
                : item.subject,
            lines,
            tone: tones.get(group) ?? 0,
            warning: (item.warnings?.length ?? 0) > 0,
            group: item.batch ?? '',
        };
    });
});

function show(query: {
    view?: View;
    target?: number | null;
    batch?: string | null;
    term?: number;
}): void {
    const next = {
        term: props.term?.id,
        view: props.selected.view,
        target: props.selected.target,
        batch: props.selected.batch,
        ...query,
    };

    router.get(
        TimetableController.index.url(),
        Object.fromEntries(
            Object.entries(next).filter(
                ([, value]) =>
                    value !== null && value !== undefined && value !== '',
            ),
        ),
        { preserveScroll: true, preserveState: true },
    );
}

const zoom = useGridZoom();

// A section's week offers only its subjects; a room's or teacher's week asks
// for the section in the dialog.
const dialogOfferings = computed(() =>
    props.selected.view === 'section'
        ? props.offerings.filter(
              (offering) => offering.section_id === props.selected.target,
          )
        : props.offerings,
);

const dialogOpen = ref(false);
const dialogTarget = ref<ClassBlock | NewClassSlot | null>(null);
let editedId: number | null = null;

function addAt(weekday: Weekday, start: string): void {
    const target = props.selected.target ?? undefined;

    dialogTarget.value = {
        weekday,
        start,
        section_id: props.selected.view === 'section' ? target : undefined,
        room_id: props.selected.view === 'room' ? target : undefined,
        teacher_id: props.selected.view === 'teacher' ? target : undefined,
        batch_name: props.selected.batch ?? undefined,
    };
    editedId = null;
    dialogOpen.value = true;
}

function edit(block: GridBlock): void {
    const item = props.classes.find((entry) => entry.id === block.id);

    if (item && !('busy' in item)) {
        dialogTarget.value = item;
        editedId = item.id;
        dialogOpen.value = true;
    }
}

// An edit can take a class out of this week, e.g. a new teacher while
// viewing the old one's week.
function saved(): void {
    const id = editedId;

    if (id !== null && !props.classes.some((entry) => entry.id === id)) {
        toast.info('The class moved out of this timetable.');
    }
}

const selectClass =
    'h-9 rounded-md border border-input bg-transparent px-3 text-sm';
</script>

<template>
    <Head title="Timetables" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Timetables"
            :description="
                canEdit
                    ? `Schedule classes by section, room or teacher${term ? ` · ${term.label}` : ''}. Click an empty half hour to add a class; click a class to change it.`
                    : `A section's, room's or teacher's week${term ? ` · ${term.label}` : ''}. This term is closed, so it is read-only.`
            "
        />

        <div class="flex flex-wrap items-center gap-3">
            <select
                v-if="terms.length > 1"
                :value="term?.id"
                :class="selectClass"
                aria-label="Term"
                @change="
                    show({
                        term: Number(
                            ($event.target as HTMLSelectElement).value,
                        ),
                        target: null,
                        batch: null,
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
            <NextTermButton
                v-if="nextTerm"
                :term="nextTerm"
                @pick="(id) => show({ term: id, target: null, batch: null })"
            />
            <select
                :value="selected.view"
                :class="selectClass"
                aria-label="View"
                @change="
                    show({
                        view: ($event.target as HTMLSelectElement)
                            .value as View,
                        target: null,
                        batch: null,
                    })
                "
            >
                <option
                    v-for="option in VIEWS"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </select>
            <select
                :value="selected.target ?? ''"
                :class="selectClass"
                :aria-label="
                    VIEWS.find((option) => option.value === selected.view)
                        ?.label
                "
                @change="
                    show({
                        target:
                            Number(
                                ($event.target as HTMLSelectElement).value,
                            ) || null,
                        batch: null,
                    })
                "
            >
                <option value="">Choose…</option>
                <option
                    v-for="option in targets"
                    :key="option.id"
                    :value="option.id"
                >
                    {{ option.label }}
                </option>
            </select>
            <select
                v-if="selected.view === 'section' && batches.length"
                :value="selected.batch ?? ''"
                :class="selectClass"
                aria-label="Batch"
                @change="
                    show({
                        batch:
                            ($event.target as HTMLSelectElement).value || null,
                    })
                "
            >
                <option value="">Whole section</option>
                <option v-for="name in batches" :key="name" :value="name">
                    Batch {{ name }}
                </option>
            </select>
            <GridZoomControl
                v-if="selected.target"
                :zoom="zoom"
                class="ml-auto"
            />
        </div>

        <ScheduleGrid
            v-if="selected.target"
            :blocks="blocks"
            :zoom="zoom"
            :editable="canEdit && dialogOfferings.length > 0"
            @slot="addAt"
            @block="edit"
        />
        <p
            v-else
            class="rounded-lg border p-8 text-center text-sm text-muted-foreground"
        >
            Choose a {{ selected.view }} to see its week.
        </p>
    </div>

    <ScheduleFormDialog
        v-model:open="dialogOpen"
        :offerings="dialogOfferings"
        :sections="selected.view === 'section' ? undefined : sections"
        :teachers="teachers"
        :rooms="rooms"
        :target="dialogTarget"
        :editable="canEdit"
        @saved="saved"
    />
</template>

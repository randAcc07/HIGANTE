<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import { computed, nextTick, onMounted, ref } from 'vue';
import CalendarController from '@/actions/App/Http/Controllers/CalendarController';
import CalendarEventFormDialog from '@/components/CalendarEventFormDialog.vue';
import ConfirmDeleteDialog from '@/components/ConfirmDeleteDialog.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/native-select';
import { formatDateRange, parseDate } from '@/lib/dates';
import { formatTime } from '@/lib/time';
import type { CalendarEvent } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Calendar', href: CalendarController.index() }],
    },
});

const props = defineProps<{
    terms: { id: number; label: string }[];
    term: {
        id: number;
        label: string;
        starts_on: string;
        ends_on: string;
    } | null;
    events: CalendarEvent[];
    programOptions: {
        id: number;
        code: string;
        programs: { id: number; code: string; name: string }[];
    }[];
    canCreate: boolean;
    newEventScope: string | null;
}>();

// Opened at one event (?event=ID, from the dashboard): scroll to it and blink it.
const highlighted = ref<number | null>(null);

onMounted(async () => {
    const id = Number(new URLSearchParams(window.location.search).get('event'));

    if (!id || !props.events.some((event) => event.id === id)) {
        return;
    }

    highlighted.value = id;
    await nextTick();
    document
        .getElementById(`event-${id}`)
        ?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    window.setTimeout(() => (highlighted.value = null), 2400);
});

/** Events grouped by the month they start in, in order. */
const months = computed(() => {
    const groups = new Map<string, CalendarEvent[]>();

    for (const event of props.events) {
        const month = parseDate(event.starts_on).toLocaleDateString(undefined, {
            month: 'long',
            year: 'numeric',
        });
        groups.set(month, [...(groups.get(month) ?? []), event]);
    }

    return [...groups.entries()];
});

function showTerm(id: number): void {
    router.get(
        CalendarController.index.url(),
        { term: id },
        { preserveScroll: true },
    );
}

/** "No classes", narrowed by programs / year levels and part of the day: "No classes for BSCS year 4 from 1:00 PM". */
function suspensionLabel(event: CalendarEvent): string {
    const parts = ['No classes'];

    if (event.programs.length || event.year_levels.length) {
        const years = event.year_levels.length
            ? `year ${event.year_levels.join(', ')}`
            : '';
        parts.push(
            `for ${[event.programs.join(', '), years].filter(Boolean).join(' ')}`,
        );
    }

    if (event.suspends_from && event.suspends_until) {
        parts.push(
            `${formatTime(event.suspends_from)}–${formatTime(event.suspends_until)}`,
        );
    } else if (event.suspends_from) {
        parts.push(`from ${formatTime(event.suspends_from)}`);
    } else if (event.suspends_until) {
        parts.push(`before ${formatTime(event.suspends_until)}`);
    }

    return parts.join(' ');
}
</script>

<template>
    <Head title="Calendar" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Calendar"
                :description="
                    term
                        ? `${term.label} · ${formatDateRange(term.starts_on, term.ends_on)}`
                        : 'No terms yet'
                "
            />
            <div class="flex gap-2">
                <NativeSelect
                    v-if="terms.length > 1"
                    :value="term?.id"
                    aria-label="Term"
                    @change="
                        showTerm(
                            Number(($event.target as HTMLSelectElement).value),
                        )
                    "
                >
                    <option
                        v-for="option in terms"
                        :key="option.id"
                        :value="option.id"
                    >
                        {{ option.label }}
                    </option>
                </NativeSelect>
                <CalendarEventFormDialog
                    v-if="canCreate"
                    :scope="newEventScope"
                    :program-options="programOptions"
                >
                    <Button><Plus class="h-4 w-4" /> Add event</Button>
                </CalendarEventFormDialog>
            </div>
        </div>

        <section
            v-for="[month, monthEvents] in months"
            :key="month"
            class="space-y-2"
        >
            <h2 class="text-sm font-medium text-muted-foreground">
                {{ month }}
            </h2>
            <ul class="divide-y rounded-lg border">
                <li
                    v-for="event in monthEvents"
                    :id="`event-${event.id}`"
                    :key="event.id"
                    class="flex items-start justify-between gap-4 p-4 transition-colors"
                    :class="
                        highlighted === event.id
                            ? 'animate-pulse bg-highlight/10 ring-2 ring-highlight ring-inset'
                            : ''
                    "
                    :data-highlighted="highlighted === event.id || undefined"
                >
                    <div class="space-y-1">
                        <p class="text-sm text-muted-foreground">
                            {{
                                formatDateRange(event.starts_on, event.ends_on)
                            }}
                        </p>
                        <p class="font-medium">
                            {{ event.title }}
                            <Badge
                                v-if="event.suspends_classes"
                                variant="destructive"
                                class="ml-2"
                                >{{ suspensionLabel(event) }}</Badge
                            >
                            <Badge variant="outline" class="ml-2">{{
                                event.college ?? 'Campus-wide'
                            }}</Badge>
                        </p>
                        <p
                            v-if="event.description"
                            class="text-sm text-muted-foreground"
                        >
                            {{ event.description }}
                        </p>
                        <p
                            v-if="event.memo_number || event.memo_url"
                            class="text-sm"
                        >
                            <a
                                v-if="event.memo_url"
                                :href="event.memo_url"
                                target="_blank"
                                rel="noopener"
                                class="text-highlight hover:underline"
                                >{{
                                    event.memo_number || 'Memorandum'
                                }}
                                (PDF)</a
                            >
                            <span v-else class="text-muted-foreground">{{
                                event.memo_number
                            }}</span>
                        </p>
                    </div>
                    <div v-if="event.can_edit" class="flex shrink-0 gap-1">
                        <CalendarEventFormDialog
                            :event="event"
                            :scope="newEventScope"
                            :program-options="programOptions"
                        >
                            <Button variant="ghost" size="sm">
                                <Pencil class="h-4 w-4" />
                                <span class="sr-only">Edit</span>
                            </Button>
                        </CalendarEventFormDialog>
                        <ConfirmDeleteDialog
                            :title="`Delete ${event.title}?`"
                            description="Days it suspended will count as class days again."
                            :url="CalendarController.destroy.url(event.id)"
                        />
                    </div>
                </li>
            </ul>
        </section>

        <p
            v-if="events.length === 0"
            class="rounded-lg border p-8 text-center text-sm text-muted-foreground"
        >
            Nothing on the calendar for this term.
        </p>
    </div>
</template>

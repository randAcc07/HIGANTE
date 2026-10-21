<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import { computed } from 'vue';
import CalendarController from '@/actions/App/Http/Controllers/CalendarController';
import CalendarEventFormDialog from '@/components/CalendarEventFormDialog.vue';
import ConfirmDeleteDialog from '@/components/ConfirmDeleteDialog.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
    canCreate: boolean;
    newEventScope: string | null;
}>();

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

/** "No classes", or the part of the day a partial suspension covers. */
function suspensionLabel(event: CalendarEvent): string {
    if (event.suspends_from && event.suspends_until) {
        return `No classes ${formatTime(event.suspends_from)}–${formatTime(event.suspends_until)}`;
    }

    if (event.suspends_from) {
        return `No classes from ${formatTime(event.suspends_from)}`;
    }

    if (event.suspends_until) {
        return `No classes before ${formatTime(event.suspends_until)}`;
    }

    return 'No classes';
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
                <select
                    v-if="terms.length > 1"
                    :value="term?.id"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
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
                </select>
                <CalendarEventFormDialog
                    v-if="canCreate"
                    :scope="newEventScope"
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
                    :key="event.id"
                    class="flex items-start justify-between gap-4 p-4"
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

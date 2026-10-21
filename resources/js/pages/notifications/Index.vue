<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import NotificationController from '@/actions/App/Http/Controllers/NotificationController';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { AppNotification, Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Notifications', href: NotificationController.index() },
        ],
    },
});

defineProps<{
    notifications: Paginated<AppNotification>;
    unreadOnly: boolean;
}>();

function open(notice: AppNotification): void {
    const go = () => notice.url && router.visit(notice.url);

    if (notice.read) {
        go();

        return;
    }

    router.post(
        NotificationController.read.url(notice.id),
        {},
        { preserveScroll: true, onSuccess: () => go() },
    );
}

function readAll(): void {
    router.post(
        NotificationController.readAll.url(),
        {},
        { preserveScroll: true },
    );
}

function when(at: string | null): string {
    return at
        ? new Date(at).toLocaleString(undefined, {
              weekday: 'short',
              month: 'short',
              day: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
          })
        : '';
}
</script>

<template>
    <Head title="Notifications" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Notifications"
                description="Everything the bell has told you"
            />
            <div class="flex gap-2">
                <Button variant="outline" size="sm" as-child>
                    <Link
                        :href="
                            NotificationController.index({
                                query: unreadOnly ? {} : { unread: 1 },
                            })
                        "
                        >{{ unreadOnly ? 'Show all' : 'Unread only' }}</Link
                    >
                </Button>
                <Button variant="outline" size="sm" @click="readAll"
                    >Mark all read</Button
                >
            </div>
        </div>

        <ul v-if="notifications.data.length" class="divide-y rounded-lg border">
            <li
                v-for="notice in notifications.data"
                :key="notice.id"
                class="flex items-start justify-between gap-4 p-4"
            >
                <div class="space-y-1">
                    <p
                        :class="
                            notice.read
                                ? 'text-muted-foreground'
                                : 'font-medium'
                        "
                    >
                        {{ notice.message }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ when(notice.at) }}
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <Badge v-if="!notice.read" variant="default">New</Badge>
                    <Button
                        v-if="notice.url || !notice.read"
                        variant="ghost"
                        size="sm"
                        @click="open(notice)"
                        >{{ notice.url ? 'Open' : 'Mark read' }}</Button
                    >
                </div>
            </li>
        </ul>
        <p
            v-else
            class="rounded-lg border p-8 text-center text-sm text-muted-foreground"
        >
            {{ unreadOnly ? 'Nothing unread.' : 'No notifications yet.' }}
        </p>

        <Pagination :paginator="notifications" />
    </div>
</template>

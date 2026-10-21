<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Bell } from '@lucide/vue';
import { computed } from 'vue';
import NotificationController from '@/actions/App/Http/Controllers/NotificationController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { AppNotification } from '@/types';

const page = usePage();
const notifications = computed(() => page.props.notifications);

function open(notice: AppNotification): void {
    const go = () => {
        if (notice.url) {
            router.visit(notice.url);
        }
    };

    if (notice.read) {
        go();

        return;
    }

    router.post(
        NotificationController.read.url(notice.id),
        {},
        { preserveScroll: true, preserveState: true, onSuccess: go },
    );
}

function readAll(): void {
    router.post(
        NotificationController.readAll.url(),
        {},
        { preserveScroll: true, preserveState: true },
    );
}

function when(at: string | null): string {
    return at
        ? new Date(at).toLocaleString(undefined, {
              month: 'short',
              day: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
          })
        : '';
}
</script>

<template>
    <DropdownMenu v-if="notifications">
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="relative"
                :aria-label="`Notifications${notifications.unread ? ` (${notifications.unread} unread)` : ''}`"
            >
                <Bell class="h-5 w-5" />
                <span
                    v-if="notifications.unread"
                    class="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-semibold text-destructive-foreground"
                    >{{
                        notifications.unread > 9 ? '9+' : notifications.unread
                    }}</span
                >
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-80">
            <div class="flex items-center justify-between gap-2 pr-1">
                <DropdownMenuLabel>Notifications</DropdownMenuLabel>
                <Button
                    v-if="notifications.unread"
                    variant="ghost"
                    size="sm"
                    class="h-7 text-xs"
                    @click="readAll"
                    >Mark all read</Button
                >
            </div>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                v-for="notice in notifications.latest"
                :key="notice.id"
                class="flex flex-col items-start gap-1 whitespace-normal"
                @select="open(notice)"
            >
                <span
                    class="text-sm"
                    :class="
                        notice.read ? 'text-muted-foreground' : 'font-medium'
                    "
                    >{{ notice.message }}</span
                >
                <span class="text-xs text-muted-foreground">{{
                    when(notice.at)
                }}</span>
            </DropdownMenuItem>
            <p
                v-if="notifications.latest.length === 0"
                class="px-2 py-4 text-center text-sm text-muted-foreground"
            >
                No notifications yet.
            </p>
            <DropdownMenuSeparator />
            <DropdownMenuItem as-child>
                <Link
                    :href="NotificationController.index()"
                    class="justify-center text-sm"
                    >See all</Link
                >
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>

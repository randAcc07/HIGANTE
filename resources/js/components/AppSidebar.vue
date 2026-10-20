<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { useEventListener, useResizeObserver } from '@vueuse/core';
import { computed, nextTick, onMounted, useTemplateRef, watch } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { lastIndicator, moveIndicator } from '@/lib/navIndicator';
import { dashboard } from '@/routes';
import { mainNavigation } from '@/navigation';

const page = usePage();
const mainNavItems = computed(() => mainNavigation(page.props.auth.user?.role));

const body = useTemplateRef('body');
const indicator = useTemplateRef('indicator');

// The pill spans the nav links and the profile button, so it lives in their
// shared wrapper and follows whichever sidebar button is marked active.
function place(animate: boolean): void {
    if (!indicator.value) {
        return;
    }

    const active = body.value?.querySelector<HTMLElement>(
        '[data-sidebar="menu-button"][data-active="true"]',
    );

    moveIndicator(indicator.value, active ?? null, animate);
}

onMounted(() => place(lastIndicator() !== null));
// page.url only changes once the new page has arrived, so the slide plays
// after the load rather than racing it.
watch(
    () => page.url,
    () => nextTick(() => place(true)),
);

// Collapsing the sidebar or scrolling the nav shifts the buttons; follow them.
useResizeObserver(body, () => place(false));
useEventListener(body, 'scroll', () => place(false), {
    capture: true,
    passive: true,
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <div ref="body" class="relative flex min-h-0 flex-1 flex-col">
            <SidebarContent>
                <NavMain :items="mainNavItems" />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>

            <span
                ref="indicator"
                aria-hidden="true"
                class="pointer-events-none absolute hidden w-[3px] rounded-full bg-highlight"
            />
        </div>
    </Sidebar>
    <slot />
</template>

<script setup lang="ts">
import { Minus, Plus } from '@lucide/vue';
import type { GridZoom } from '@/composables/useGridZoom';

/**
 * The − / + zoom buttons for a ScheduleGrid, for pages that put them in
 * their own toolbar; share the same useGridZoom() with the grid.
 */
defineProps<{
    zoom: GridZoom;
}>();
</script>

<template>
    <div class="flex items-center gap-2 text-xs text-muted-foreground">
        <span class="hidden lg:inline">Ctrl+scroll or pinch to zoom</span>
        <div
            class="inline-flex h-9 items-center gap-1 rounded-md border px-1"
            role="group"
            aria-label="Grid zoom"
        >
            <button
                type="button"
                class="rounded p-1 hover:bg-muted disabled:opacity-40"
                aria-label="Zoom out"
                :disabled="!zoom.canZoomOut.value"
                @click="zoom.zoomOut"
            >
                <Minus class="h-3.5 w-3.5" />
            </button>
            <span
                class="min-w-12 text-center font-medium uppercase"
                data-test="zoom-level"
                >{{ zoom.zoomLabel.value }}</span
            >
            <button
                type="button"
                class="rounded p-1 hover:bg-muted disabled:opacity-40"
                aria-label="Zoom in"
                :disabled="!zoom.canZoomIn.value"
                @click="zoom.zoomIn"
            >
                <Plus class="h-3.5 w-3.5" />
            </button>
        </div>
    </div>
</template>

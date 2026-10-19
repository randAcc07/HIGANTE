import { computed, onScopeDispose, ref } from 'vue';

/**
 * Zoom for the weekly grid, adapted from LSIMS's useScheduleGrid. Named
 * levels (the − / + buttons and the label) are points along one continuous
 * pixels-per-minute value, which Ctrl+scroll and a trackpad or touch pinch
 * drive live. Each grid gets its own zoom; it is not remembered.
 *
 * Zooming changes the grid's size, not its lines: classes are scheduled in
 * 30-minute steps, so lines and time labels are never finer than that
 * (hourly when zoomed out).
 */
const LEVELS = [
    { ppm: 28 / 60, label: '50%' },
    { ppm: 28 / 30, label: '100%' },
    { ppm: 28 / 15, label: '200%' },
    { ppm: 28 / 5, label: '600%' },
] as const;

/** 100%: a 30-minute row is 28px tall. */
const DEFAULT_LEVEL = 1;

const LEVEL_PPM = LEVELS.map((level) => level.ppm);

const WHEEL_SENSITIVITY = 0.008;
const TOUCH_SENSITIVITY = 0.006;

export function useGridZoom() {
    const pixelsPerMinute = ref(LEVEL_PPM[DEFAULT_LEVEL]);

    // The named level nearest the current value, measured in log space: the
    // levels are roughly exponential, so a linear distance would favour the
    // coarse end.
    const levelIndex = computed(() => {
        const current = Math.log(pixelsPerMinute.value);
        let nearest = 0;

        LEVEL_PPM.forEach((ppm, index) => {
            if (
                Math.abs(current - Math.log(ppm)) <
                Math.abs(current - Math.log(LEVEL_PPM[nearest]))
            ) {
                nearest = index;
            }
        });

        return nearest;
    });

    /** Hourly lines when zoomed out, else every 30 minutes, never finer. */
    const labelMinutes = computed(() => (levelIndex.value === 0 ? 60 : 30));
    const zoomLabel = computed(() => LEVELS[levelIndex.value].label);
    const canZoomIn = computed(() => levelIndex.value < LEVELS.length - 1);
    const canZoomOut = computed(() => levelIndex.value > 0);

    // The buttons jump exactly one named level.
    function zoomIn(): void {
        if (canZoomIn.value) {
            pixelsPerMinute.value = LEVEL_PPM[levelIndex.value + 1];
        }
    }

    function zoomOut(): void {
        if (canZoomOut.value) {
            pixelsPerMinute.value = LEVEL_PPM[levelIndex.value - 1];
        }
    }

    // Gestures scale multiplicatively (an additive step would feel too fast
    // at the coarse end and too slow at the fine end), and are coalesced to
    // one write per animation frame, since wheel and touch events fire far
    // faster than the grid needs to re-render.
    let pendingExponent = 0;
    let frame: number | null = null;

    function applyContinuousZoom(delta: number, sensitivity: number): void {
        if (delta === 0) {
            return;
        }

        pendingExponent += delta * sensitivity;

        if (frame !== null) {
            return;
        }

        frame = requestAnimationFrame(() => {
            frame = null;
            const next = pixelsPerMinute.value * Math.exp(pendingExponent);
            pendingExponent = 0;
            pixelsPerMinute.value = Math.min(
                Math.max(next, LEVEL_PPM[0]),
                LEVEL_PPM[LEVEL_PPM.length - 1],
            );
        });
    }

    onScopeDispose(() => {
        if (frame !== null) {
            cancelAnimationFrame(frame);
        }
    });

    // A trackpad pinch arrives as a wheel event with ctrlKey set, like
    // Ctrl+scroll on a mouse. A plain wheel is left alone so it still
    // scrolls; preventDefault() stops the browser zooming the page.
    function onWheel(event: WheelEvent): void {
        if (!event.ctrlKey) {
            return;
        }

        event.preventDefault();
        applyContinuousZoom(-event.deltaY, WHEEL_SENSITIVITY);
    }

    let lastPinchDistance: number | null = null;

    function pinchDistance(touches: TouchList): number {
        return Math.hypot(
            touches[1].clientX - touches[0].clientX,
            touches[1].clientY - touches[0].clientY,
        );
    }

    function onTouchStart(event: TouchEvent): void {
        if (event.touches.length === 2) {
            lastPinchDistance = pinchDistance(event.touches);
        }
    }

    function onTouchMove(event: TouchEvent): void {
        if (event.touches.length !== 2 || lastPinchDistance === null) {
            return;
        }

        event.preventDefault();
        const distance = pinchDistance(event.touches);
        applyContinuousZoom(distance - lastPinchDistance, TOUCH_SENSITIVITY);
        lastPinchDistance = distance;
    }

    // Fewer than two fingers ends the pinch, so the next one starts fresh.
    function onTouchEnd(event: TouchEvent): void {
        if (event.touches.length < 2) {
            lastPinchDistance = null;
        }
    }

    return {
        pixelsPerMinute,
        labelMinutes,
        zoomLabel,
        canZoomIn,
        canZoomOut,
        zoomIn,
        zoomOut,
        onWheel,
        onTouchStart,
        onTouchMove,
        onTouchEnd,
    };
}

export type GridZoom = ReturnType<typeof useGridZoom>;

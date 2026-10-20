// The sliding pill beside the active sidebar button. Moving between buttons it
// first stretches to span both, then shrinks into the new one (the Windows 11
// navigation-view motion).

type Box = { top: number; height: number };

const DURATION_MS = 360;
const EASING = 'cubic-bezier(0.2, 0, 0, 1)';

// Module scope so the last spot survives the sidebar remounting between pages.
let last: Box | null = null;

export function lastIndicator(): Box | null {
    return last;
}

// Measured against the pill's own container: each button's offsetTop is
// relative to its <li>, which is positioned, so it would always be 0.
function measure(el: HTMLElement, button: HTMLElement): Box & { left: number } {
    const container = (el.offsetParent ?? el.parentElement) as HTMLElement;
    const outer = container.getBoundingClientRect();
    const rect = button.getBoundingClientRect();
    // Half the button's height, so taller buttons get a longer pill.
    const height = Math.round(rect.height / 2);

    return {
        top:
            rect.top -
            outer.top -
            container.clientTop +
            (rect.height - height) / 2,
        left: rect.left - outer.left - container.clientLeft,
        height,
    };
}

function frame({ top, height }: Box): Keyframe {
    return { top: `${top}px`, height: `${height}px` };
}

// Where the pill is drawn right now, mid-animation included.
function current(el: HTMLElement): Box | null {
    if (el.classList.contains('hidden')) {
        return last;
    }

    const style = getComputedStyle(el);

    return { top: parseFloat(style.top), height: parseFloat(style.height) };
}

export function moveIndicator(
    el: HTMLElement,
    button: HTMLElement | null,
    animate: boolean,
): void {
    if (!button) {
        el.getAnimations().forEach((animation) => animation.cancel());
        el.classList.add('hidden');
        last = null;

        return;
    }

    const from = current(el);
    const { left, ...to } = measure(el, button);

    el.getAnimations().forEach((animation) => animation.cancel());
    el.classList.remove('hidden');
    Object.assign(el.style, frame(to), { left: `${left}px` });
    last = to;

    const reduced = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;

    if (
        !animate ||
        reduced ||
        !from ||
        (from.top === to.top && from.height === to.height)
    ) {
        return;
    }

    const goingDown = to.top > from.top;
    const stretched: Box = goingDown
        ? { top: from.top, height: to.top + to.height - from.top }
        : { top: to.top, height: from.top + from.height - to.top };

    // Easing on each keyframe so both halves ease on their own: a quick
    // stretch, then a soft settle.
    el.animate(
        [
            { ...frame(from), easing: EASING },
            { ...frame(stretched), easing: EASING },
            frame(to),
        ],
        DURATION_MS,
    );
}

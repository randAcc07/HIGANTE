import type { Directive } from 'vue';

/**
 * On a table's horizontal scroll wrapper: sets `data-scroll-more` while
 * columns are still hidden past the right edge, so the pinned actions column
 * (`.table-actions` in app.css) casts a shadow over what scrolls beneath it.
 */
const cleanups = new WeakMap<HTMLElement, () => void>();

function update(el: HTMLElement): void {
    // 1px slack: zoomed and high-DPI screens report fractional widths.
    el.toggleAttribute(
        'data-scroll-more',
        el.scrollWidth - el.clientWidth - el.scrollLeft > 1,
    );
}

export const tableScroll: Directive<HTMLElement> = {
    mounted(el) {
        const run = (): void => update(el);
        // The wrapper resizes with the viewport, the table with its rows.
        const observer = new ResizeObserver(run);

        observer.observe(el);

        if (el.firstElementChild) {
            observer.observe(el.firstElementChild);
        }

        el.addEventListener('scroll', run, { passive: true });
        cleanups.set(el, () => {
            el.removeEventListener('scroll', run);
            observer.disconnect();
        });
        run();
    },
    unmounted(el) {
        cleanups.get(el)?.();
        cleanups.delete(el);
    },
};

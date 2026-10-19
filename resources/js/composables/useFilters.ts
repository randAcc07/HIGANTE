import { router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

/**
 * Keeps a list page's filters in the URL query, so a filtered list can be
 * bookmarked and survives a refresh. Text filters wait for typing to pause.
 */
export function useFilters<T extends Record<string, string | number | null>>(
    url: string,
    initial: T,
): T {
    const filters = reactive({ ...initial }) as T;
    let timer: ReturnType<typeof setTimeout> | undefined;

    watch(
        () => ({ ...filters }),
        () => {
            clearTimeout(timer);
            timer = setTimeout(() => {
                const query = Object.fromEntries(
                    Object.entries(filters).filter(
                        ([, value]) => value !== null && value !== '',
                    ),
                );

                router.get(url, query, {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                });
            }, 300);
        },
    );

    return filters;
}

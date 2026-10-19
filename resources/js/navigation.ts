import { LayoutGrid } from '@lucide/vue';
import { dashboard } from '@/routes';
import type { NavItem, UserRole } from '@/types';

/**
 * The main menu for each role. Features add their pages here; the server
 * still enforces access on every route.
 */
const itemsByRole: Record<UserRole, () => NavItem[]> = {
    admin: () => [],
    registrar: () => [],
    dean: () => [],
    teacher: () => [],
    learner: () => [],
};

export function mainNavigation(role: UserRole | undefined): NavItem[] {
    const shared: NavItem[] = [
        { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
    ];

    return role ? [...shared, ...itemsByRole[role]()] : shared;
}

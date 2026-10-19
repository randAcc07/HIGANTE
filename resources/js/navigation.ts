import { BookOpen, Building2, DoorOpen, LayoutGrid } from '@lucide/vue';
import CollegeController from '@/actions/App/Http/Controllers/Admin/CollegeController';
import RoomController from '@/actions/App/Http/Controllers/Admin/RoomController';
import SubjectController from '@/actions/App/Http/Controllers/Admin/SubjectController';
import { dashboard } from '@/routes';
import type { NavItem, UserRole } from '@/types';

/**
 * The main menu for each role. Features add their pages here; the server
 * still enforces access on every route.
 */
const itemsByRole: Record<UserRole, () => NavItem[]> = {
    admin: () => [
        { title: 'Colleges', href: CollegeController.index(), icon: Building2 },
        { title: 'Subjects', href: SubjectController.index(), icon: BookOpen },
        { title: 'Rooms', href: RoomController.index(), icon: DoorOpen },
    ],
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

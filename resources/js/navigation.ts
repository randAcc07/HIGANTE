import {
    BookOpen,
    Building2,
    CalendarDays,
    CalendarRange,
    DoorOpen,
    LayoutGrid,
} from '@lucide/vue';
import CollegeController from '@/actions/App/Http/Controllers/Admin/CollegeController';
import RoomController from '@/actions/App/Http/Controllers/Admin/RoomController';
import SubjectController from '@/actions/App/Http/Controllers/Admin/SubjectController';
import TermController from '@/actions/App/Http/Controllers/Admin/TermController';
import CalendarController from '@/actions/App/Http/Controllers/CalendarController';
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
        { title: 'Terms', href: TermController.index(), icon: CalendarRange },
    ],
    registrar: () => [],
    dean: () => [],
    teacher: () => [],
    learner: () => [],
};

export function mainNavigation(role: UserRole | undefined): NavItem[] {
    const shared: NavItem[] = [
        { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
        {
            title: 'Calendar',
            href: CalendarController.index(),
            icon: CalendarDays,
        },
    ];

    return role ? [...shared, ...itemsByRole[role]()] : shared;
}

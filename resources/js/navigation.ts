import {
    BookOpen,
    Building2,
    CalendarDays,
    CalendarClock,
    CalendarRange,
    ClipboardCheck,
    DoorOpen,
    GraduationCap,
    LayoutGrid,
    LayoutList,
    Users,
} from '@lucide/vue';
import CollegeController from '@/actions/App/Http/Controllers/Admin/CollegeController';
import RoomController from '@/actions/App/Http/Controllers/Admin/RoomController';
import SubjectController from '@/actions/App/Http/Controllers/Admin/SubjectController';
import AccountController from '@/actions/App/Http/Controllers/Admin/AccountController';
import TermController from '@/actions/App/Http/Controllers/Admin/TermController';
import CalendarController from '@/actions/App/Http/Controllers/CalendarController';
import SectionController from '@/actions/App/Http/Controllers/Dean/SectionController';
import TimetableController from '@/actions/App/Http/Controllers/Dean/TimetableController';
import LearnerController from '@/actions/App/Http/Controllers/Registrar/LearnerController';
import RegistrationController from '@/actions/App/Http/Controllers/Registrar/RegistrationController';
import { dashboard } from '@/routes';
import type { NavItem, UserRole } from '@/types';

/**
 * The main menu for each role. Features add their pages here; the server
 * still enforces access on every route.
 */
const itemsByRole: Record<UserRole, () => NavItem[]> = {
    admin: () => [
        { title: 'Accounts', href: AccountController.index(), icon: Users },
        { title: 'Colleges', href: CollegeController.index(), icon: Building2 },
        { title: 'Subjects', href: SubjectController.index(), icon: BookOpen },
        { title: 'Rooms', href: RoomController.index(), icon: DoorOpen },
        { title: 'Terms', href: TermController.index(), icon: CalendarRange },
    ],
    registrar: () => [
        {
            title: 'Learners',
            href: LearnerController.index(),
            icon: GraduationCap,
        },
        {
            title: 'Registration',
            href: RegistrationController.index(),
            icon: ClipboardCheck,
        },
    ],
    dean: () => [
        {
            title: 'Sections',
            href: SectionController.index(),
            icon: LayoutList,
        },
        {
            title: 'Timetables',
            href: TimetableController.index(),
            icon: CalendarClock,
        },
    ],
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

import {
    BookOpen,
    Building2,
    CalendarCheck,
    CalendarDays,
    CalendarClock,
    CalendarRange,
    ClipboardCheck,
    ClipboardList,
    TriangleAlert,
    DoorOpen,
    FileCheck,
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
import DeanAttendanceController from '@/actions/App/Http/Controllers/Dean/AttendanceController';
import DeanExcuseController from '@/actions/App/Http/Controllers/Dean/ExcuseController';
import DeanLearnerController from '@/actions/App/Http/Controllers/Dean/LearnerController';
import StandingController from '@/actions/App/Http/Controllers/Dean/StandingController';
import SectionController from '@/actions/App/Http/Controllers/Dean/SectionController';
import TimetableController from '@/actions/App/Http/Controllers/Dean/TimetableController';
import LearnerController from '@/actions/App/Http/Controllers/Registrar/LearnerController';
import LearnerExcuseController from '@/actions/App/Http/Controllers/Learner/ExcuseController';
import RegistrationController from '@/actions/App/Http/Controllers/Registrar/RegistrationController';
import ClassController from '@/actions/App/Http/Controllers/Teacher/ClassController';
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
        {
            title: 'Learners',
            href: DeanLearnerController.index(),
            icon: GraduationCap,
        },
        {
            title: 'Attendance',
            href: DeanAttendanceController.index(),
            icon: ClipboardList,
        },
        {
            title: 'At risk',
            href: StandingController.index(),
            icon: TriangleAlert,
        },
        {
            title: 'Excuses',
            href: DeanExcuseController.index(),
            icon: FileCheck,
        },
    ],
    teacher: () => [
        {
            title: 'My classes',
            href: ClassController.index(),
            icon: CalendarCheck,
        },
    ],
    learner: () => [
        {
            title: 'Excuses',
            href: LearnerExcuseController.index(),
            icon: FileCheck,
        },
    ],
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

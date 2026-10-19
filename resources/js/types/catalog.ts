export type College = {
    id: number;
    code: string;
    name: string;
    programs_count?: number;
    subjects_count?: number;
    staff_count?: number;
    learner_count?: number;
};

export type Program = {
    id: number;
    college_id: number;
    code: string;
    name: string;
    learners_count?: number;
    sections_count?: number;
};

export type Subject = {
    id: number;
    college_id: number;
    code: string;
    title: string;
    lecture_units: string;
    laboratory_units: string;
    college?: Pick<College, 'id' | 'code'>;
    offerings_count?: number;
};

export type RoomType = 'lecture' | 'laboratory';

export type Room = {
    id: number;
    code: string;
    building: string | null;
    floor: number | null;
    capacity: number | null;
    room_type: RoomType;
    is_active: boolean;
    schedules_count?: number;
};

/** Laravel's LengthAwarePaginator, as Inertia receives it. */
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

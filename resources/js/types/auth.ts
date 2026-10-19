export type UserRole = 'admin' | 'registrar' | 'dean' | 'teacher' | 'learner';

export type User = {
    id: number;
    role: UserRole;
    college_id: number | null;
    is_active: boolean;
    last_name: string;
    first_name: string;
    middle_name: string | null;
    suffix: string | null;
    full_name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};

export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User | null;
    is_admin: boolean;
};

export type LoginPayload = {
    email: string;
    password: string;
    remember: boolean;
};

export type RegisterPayload = {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    terms: boolean;
};

export type ValidationErrors = Record<string, string[]>;

export type AuthApiResponse = {
    message?: string;
    user?: User;
    token?: string;
    access_token?: string;
    redirect?: string;
};

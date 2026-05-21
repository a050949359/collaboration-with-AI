import type {
    AuthApiResponse,
    ChangePasswordPayload,
    ForgotPasswordPayload,
    LoginPayload,
    RegisterPayload,
    ResetPasswordPayload,
    ValidationErrors,
} from '@/types';
import { api } from './routes';

type ApiErrorPayload = {
    message?: string;
    errors?: ValidationErrors;
};

export class AuthApiError extends Error {
    status: number;
    fieldErrors: ValidationErrors;
    payload?: unknown;

    constructor(message: string, status: number, fieldErrors: ValidationErrors = {}, payload?: unknown) {
        super(message);
        this.name = 'AuthApiError';
        this.status = status;
        this.fieldErrors = fieldErrors;
        this.payload = payload;
    }
}

const baseUrl = (import.meta.env.VITE_API_BASE_URL || '').replace(/\/$/, '');

function resolveUrl(path: string) {
    if (/^https?:\/\//.test(path)) {
        return path;
    }

    return `${baseUrl}${path.startsWith('/') ? path : `/${path}`}`;
}

function extractErrorMessage(payload: unknown, fallback: string) {
    if (!payload || typeof payload !== 'object') {
        return fallback;
    }

    return (payload as ApiErrorPayload).message || fallback;
}

function extractFieldErrors(payload: unknown) {
    if (!payload || typeof payload !== 'object') {
        return {};
    }

    return (payload as ApiErrorPayload).errors || {};
}

async function parseResponse(response: Response) {
    const contentType = response.headers.get('content-type') || '';

    if (contentType.includes('application/json')) {
        return response.json();
    }

    const text = await response.text();

    return text ? { message: text } : null;
}

async function request<T>(path: string, payload: unknown) {
    const response = await fetch(resolveUrl(path), {
        method: 'POST',
        credentials: 'include',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });

    const parsed = await parseResponse(response);

    if (!response.ok) {
        throw new AuthApiError(
            extractErrorMessage(parsed, 'API request failed.'),
            response.status,
            extractFieldErrors(parsed),
            parsed,
        );
    }

    return parsed as T;
}

export async function loginWithApi(payload: LoginPayload) {
    return request<AuthApiResponse>(api.auth.login(), payload);
}

export async function registerWithApi(payload: RegisterPayload) {
    return request<AuthApiResponse>(api.auth.register(), payload);
}

export async function logoutWithApi() {
    await request<{ message?: string }>(api.auth.logout(), {});
}

export async function forgotPasswordWithApi(payload: ForgotPasswordPayload) {
    return request<{ message?: string }>(api.auth.forgotPassword(), payload);
}

export async function resetPasswordWithApi(payload: ResetPasswordPayload) {
    return request<{ message?: string }>(api.auth.resetPassword(), payload);
}

export async function changePasswordWithApi(payload: ChangePasswordPayload) {
    return request<{ message?: string }>(api.auth.changePassword(), payload);
}

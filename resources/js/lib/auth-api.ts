import type {
    AuthApiResponse,
    ChangePasswordPayload,
    ForgotPasswordPayload,
    LoginPayload,
    RegisterPayload,
    ResetPasswordPayload,
    TwoFactorConfirmResponse,
    TwoFactorCredentialPayload,
    TwoFactorEnableResponse,
    User,
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

    constructor(
        message: string,
        status: number,
        fieldErrors: ValidationErrors = {},
        payload?: unknown,
    ) {
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
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
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

export async function enableTwoFactorWithApi() {
    return request<TwoFactorEnableResponse>(api.auth.twoFactor.enable(), {});
}

export async function confirmTwoFactorWithApi(payload: { code: string }) {
    return request<TwoFactorConfirmResponse>(
        api.auth.twoFactor.confirm(),
        payload,
    );
}

// payload.password 需先經 encryptPassword() RSA 加密
export async function disableTwoFactorWithApi(
    payload: TwoFactorCredentialPayload,
) {
    return request<{ message?: string; user?: User }>(
        api.auth.twoFactor.disable(),
        payload,
    );
}

export async function regenerateRecoveryCodesWithApi(
    payload: TwoFactorCredentialPayload,
) {
    return request<{ recovery_codes: string[] }>(
        api.auth.twoFactor.recoveryCodes(),
        payload,
    );
}

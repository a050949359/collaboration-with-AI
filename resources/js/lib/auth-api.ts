import type {
    AuthApiResponse,
    LoginPayload,
    RegisterPayload,
    ValidationErrors,
} from '@/types';

type ApiErrorPayload = {
    message?: string;
    errors?: ValidationErrors;
};

type AuthApiConfig = {
    baseUrl: string;
    loginEndpoint: string;
    registerEndpoint: string;
    logoutEndpoint: string;
    csrfEndpoint: string;
    useCsrf: boolean;
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

const authApiConfig: AuthApiConfig = {
    baseUrl: (import.meta.env.VITE_API_BASE_URL || '').replace(/\/$/, ''),
    loginEndpoint: import.meta.env.VITE_AUTH_LOGIN_ENDPOINT || '/api/auth/login',
    registerEndpoint: import.meta.env.VITE_AUTH_REGISTER_ENDPOINT || '/api/auth/register',
    logoutEndpoint: import.meta.env.VITE_AUTH_LOGOUT_ENDPOINT || '/api/auth/logout',
    csrfEndpoint: import.meta.env.VITE_AUTH_CSRF_ENDPOINT || '/sanctum/csrf-cookie',
    useCsrf: import.meta.env.VITE_AUTH_USE_CSRF !== 'false',
};

function resolveUrl(path: string) {
    if (/^https?:\/\//.test(path)) {
        return path;
    }

    return `${authApiConfig.baseUrl}${path.startsWith('/') ? path : `/${path}`}`;
}

function extractErrorMessage(payload: unknown, fallback: string) {
    if (!payload || typeof payload !== 'object') {
        return fallback;
    }

    const maybePayload = payload as ApiErrorPayload;

    return maybePayload.message || fallback;
}

function extractFieldErrors(payload: unknown) {
    if (!payload || typeof payload !== 'object') {
        return {};
    }

    const maybePayload = payload as ApiErrorPayload;

    return maybePayload.errors || {};
}

async function parseResponse(response: Response) {
    const contentType = response.headers.get('content-type') || '';

    if (contentType.includes('application/json')) {
        return response.json();
    }

    const text = await response.text();

    return text ? { message: text } : null;
}

async function ensureCsrfCookie() {
    if (!authApiConfig.useCsrf) {
        return;
    }

    await fetch(resolveUrl(authApiConfig.csrfEndpoint), {
        credentials: 'include',
        headers: {
            Accept: 'application/json',
        },
    });
}

async function request<T>(path: string, payload: unknown) {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
    };

    const response = await fetch(resolveUrl(path), {
        method: 'POST',
        credentials: 'include',
        headers,
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
    await ensureCsrfCookie();

    return request<AuthApiResponse>(authApiConfig.loginEndpoint, payload);
}

export async function registerWithApi(payload: RegisterPayload) {
    await ensureCsrfCookie();

    return request<AuthApiResponse>(authApiConfig.registerEndpoint, payload);
}

export async function logoutWithApi() {
    await ensureCsrfCookie();
    await request<{ message?: string }>(authApiConfig.logoutEndpoint, {});
}

export function getAuthApiConfig() {
    return {
        ...authApiConfig,
    };
}

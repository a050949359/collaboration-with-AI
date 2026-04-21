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
    tokenStorageKey: string;
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
    tokenStorageKey: import.meta.env.VITE_AUTH_TOKEN_KEY || 'auth_token',
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

export function getStoredToken(): string | null {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.localStorage.getItem(authApiConfig.tokenStorageKey);
}

function clearStoredToken() {
    if (typeof window !== 'undefined') {
        window.localStorage.removeItem(authApiConfig.tokenStorageKey);
    }
}

async function request<T>(path: string, payload: unknown, withToken = false) {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
    };

    if (withToken) {
        const token = getStoredToken();

        if (token) {
            headers['Authorization'] = `Bearer ${token}`;
        }
    }

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

function persistToken(response: AuthApiResponse) {
    const token = response.access_token || response.token;

    if (!token || typeof window === 'undefined') {
        return;
    }

    window.localStorage.setItem(authApiConfig.tokenStorageKey, token);
}

export async function loginWithApi(payload: LoginPayload) {
    await ensureCsrfCookie();

    const response = await request<AuthApiResponse>(authApiConfig.loginEndpoint, payload);
    persistToken(response);

    return response;
}

export async function registerWithApi(payload: RegisterPayload) {
    await ensureCsrfCookie();

    const response = await request<AuthApiResponse>(authApiConfig.registerEndpoint, payload);
    persistToken(response);

    return response;
}

export async function logoutWithApi() {
    await ensureCsrfCookie();
    await request<{ message?: string }>(authApiConfig.logoutEndpoint, {}, true);
    clearStoredToken();
}

export function getAuthApiConfig() {
    return {
        ...authApiConfig,
    };
}
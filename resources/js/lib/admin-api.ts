import { api } from './routes';

type ApiEnvelope<T> = {
    status: 'success' | 'error';
    message?: string;
    data: T;
};

export type AdminSettings = {
    site_name: string;
    maintenance_mode: boolean;
    allow_registration: boolean;
    max_login_attempts: number;
    avatar_size: number;
};

export class AdminApiError extends Error {
    status: number;

    constructor(message: string, status: number) {
        super(message);
        this.name = 'AdminApiError';
        this.status = status;
    }
}

async function parseJson<T>(response: Response): Promise<T> {
    const payload = await response.json();

    if (!response.ok) {
        throw new AdminApiError(
            payload?.message || 'Request failed.',
            response.status,
        );
    }

    return payload as T;
}

export async function fetchAdminSettings(): Promise<AdminSettings> {
    const response = await fetch(api.admin.settings(), {
        credentials: 'include',
        headers: { Accept: 'application/json' },
    });

    const payload = await parseJson<ApiEnvelope<AdminSettings>>(response);

    return payload.data;
}

export async function saveAdminSettings(
    settings: AdminSettings,
): Promise<{ message: string; settings: AdminSettings }> {
    const response = await fetch(api.admin.settings(), {
        method: 'PATCH',
        credentials: 'include',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(settings),
    });

    const payload = await parseJson<{
        message: string;
        settings: AdminSettings;
    }>(response);

    return payload;
}

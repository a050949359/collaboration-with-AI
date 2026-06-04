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

// ── LLM provider/model 設定 ──────────────────────────────

export type LlmSelection = { provider: string; model: string };
export type LlmSettings = Record<string, LlmSelection>;
export type LlmTestResult = {
    ok: boolean;
    latency_ms: number;
    reply?: string;
    error?: string;
};

/** 局部更新 settings：只送 llm 區（後端 sometimes 驗證，其餘欄位保留）。 */
export async function saveLlmSettings(
    llm: LlmSettings,
): Promise<{ message: string }> {
    const response = await fetch(api.admin.settings(), {
        method: 'PATCH',
        credentials: 'include',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ llm }),
    });

    return parseJson<{ message: string }>(response);
}

/** 連線測試：失敗時後端仍回 200（ok:false），故直接讀回應。 */
export async function testLlmConnection(
    provider: string,
    model: string,
    withSchema = false,
): Promise<LlmTestResult> {
    const response = await fetch(api.admin.llmTest(), {
        method: 'POST',
        credentials: 'include',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ provider, model, with_schema: withSchema }),
    });

    return parseJson<LlmTestResult>(response);
}

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
export type ImageSettings = { model: string };

export async function saveLlmSettings(
    llm: LlmSettings,
    image?: ImageSettings,
): Promise<{ message: string }> {
    const response = await fetch(api.admin.settings(), {
        method: 'PATCH',
        credentials: 'include',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(image ? { llm, image } : { llm }),
    });

    return parseJson<{ message: string }>(response);
}

// ── 語音設定（TTS / STT / Live 即時翻譯）──────────────────

export type VoiceSettings = {
    live: { model: string };
    tts: { model: string; voice: string };
    stt: { model: string };
};

/** 局部更新：只送 live/tts/stt 三區（後端 sometimes 驗證，其餘保留）。 */
export async function saveVoiceSettings(
    voice: VoiceSettings,
): Promise<{ message: string }> {
    const response = await fetch(api.admin.settings(), {
        method: 'PATCH',
        credentials: 'include',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(voice),
    });

    return parseJson<{ message: string }>(response);
}

// ── 工具設定（Gemini tools：Google Search / Google Maps）──────

export type ToolsSettings = { search_model: string; map_model: string };

/** 局部更新：只送 tools 區（後端 sometimes 驗證，其餘保留）。 */
export async function saveToolsSettings(
    tools: ToolsSettings,
): Promise<{ message: string }> {
    const response = await fetch(api.admin.settings(), {
        method: 'PATCH',
        credentials: 'include',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ tools }),
    });

    return parseJson<{ message: string }>(response);
}

/** 文轉語音：回傳 WAV blob（可選 target 先翻再念）。 */
export async function ttsSynthesize(
    text: string,
    target?: string,
    voice?: string,
): Promise<Blob> {
    const response = await fetch(api.ai.tts(), {
        method: 'POST',
        credentials: 'include',
        headers: { Accept: 'audio/wav', 'Content-Type': 'application/json' },
        body: JSON.stringify({
            text,
            target: target || null,
            voice: voice || null,
        }),
    });

    if (!response.ok) {
        const msg = await response.text();

        throw new AdminApiError(msg || 'TTS failed.', response.status);
    }

    return response.blob();
}

/** 語音轉文：上傳音檔 blob，回傳轉錄文字。 */
export async function sttTranscribe(audio: Blob): Promise<string> {
    const form = new FormData();
    form.append('audio', audio, 'audio.wav');

    const response = await fetch(api.ai.stt(), {
        method: 'POST',
        credentials: 'include',
        headers: { Accept: 'application/json' },
        body: form,
    });

    const payload = await parseJson<{ text: string }>(response);

    return payload.text;
}

/** 鑄 Live ephemeral token（測試用，回 token + 到期時間）。 */
export async function mintLiveToken(
    target: string,
): Promise<{ token: string; expiresAt: string }> {
    const response = await fetch(api.ai.liveToken(), {
        method: 'POST',
        credentials: 'include',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ target }),
    });

    return parseJson<{ token: string; expiresAt: string }>(response);
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

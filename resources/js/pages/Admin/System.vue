<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted, watchEffect } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    AdminApiError,
    fetchAdminSettings,
    saveAdminSettings,
    saveLlmSettings,
    testLlmConnection,
} from '@/lib/admin-api';
import type {
    AdminSettings,
    LlmSettings,
    LlmTestResult,
} from '@/lib/admin-api';
import { api, routes } from '@/lib/routes';

type Tab = 'settings' | 'tokens' | 'llm' | 'micro-host';
const activeTab = ref<Tab>('settings');

// ── Settings ─────────────────────────────────────────────

const page = usePage();
const defaultSiteName = String(page.props.name || 'CHY Lab');

const DEFAULTS: AdminSettings = {
    site_name: defaultSiteName,
    maintenance_mode: false,
    allow_registration: true,
    max_login_attempts: 5,
    avatar_size: 128,
};

const settingsForm = ref<AdminSettings>({ ...DEFAULTS });
const settingsLoading = ref(true);
const isSaving = ref(false);
const settingsSuccess = ref('');
const settingsError = ref('');

async function loadSettings() {
    try {
        const data = await fetchAdminSettings();
        settingsForm.value = { ...DEFAULTS, ...data };
    } catch {
        settingsError.value = '載入設定失敗';
    } finally {
        settingsLoading.value = false;
    }
}

async function saveSettings() {
    if (isSaving.value) {
        return;
    }

    isSaving.value = true;
    settingsSuccess.value = '';
    settingsError.value = '';

    try {
        const result = await saveAdminSettings(settingsForm.value);
        settingsForm.value = { ...DEFAULTS, ...result.settings };
        settingsSuccess.value = result.message || '設定已更新';
    } catch (e: unknown) {
        settingsError.value =
            e instanceof AdminApiError ? e.message : '連線異常，請稍後再試';
    } finally {
        isSaving.value = false;
    }
}

// ── LLM 模型設定 ─────────────────────────────────────────

const LLM_USES = [
    { key: 'story', label: '故事段落生成' },
    { key: 'story_state', label: '世界狀態更新' },
    { key: 'character', label: '角色生成' },
    { key: 'chat', label: 'About 履歷對話' },
] as const;

const llmCatalog = computed<Record<string, string[]>>(
    () => (page.props.llmCatalog as Record<string, string[]>) ?? {},
);
const llmProviders = computed(() => Object.keys(llmCatalog.value));

function modelsFor(provider: string): string[] {
    return llmCatalog.value[provider] ?? [];
}

const llmForm = ref<LlmSettings>(
    Object.fromEntries(
        LLM_USES.map((u) => {
            const existing = (
                page.props.llmSettings as LlmSettings | undefined
            )?.[u.key];

            return [
                u.key,
                {
                    provider: existing?.provider ?? 'gemini',
                    model: existing?.model ?? '',
                },
            ];
        }),
    ),
);

function onProviderChange(useKey: string) {
    const sel = llmForm.value[useKey];
    const models = modelsFor(sel.provider);

    if (models.length && !models.includes(sel.model)) {
        sel.model = models[0];
    }
}

const llmSaving = ref(false);
const llmSaveMsg = ref('');
const llmSaveErr = ref('');

async function saveLlm() {
    if (llmSaving.value) {
        return;
    }

    llmSaving.value = true;
    llmSaveMsg.value = '';
    llmSaveErr.value = '';

    try {
        const r = await saveLlmSettings(llmForm.value);
        llmSaveMsg.value = r.message || '模型設定已更新';
    } catch (e: unknown) {
        llmSaveErr.value =
            e instanceof AdminApiError ? e.message : '儲存失敗，請稍後再試';
    } finally {
        llmSaving.value = false;
    }
}

const llmTesting = ref<Record<string, boolean>>({});
const llmTestSchema = ref<Record<string, boolean>>({});
const llmResults = ref<Record<string, LlmTestResult>>({});

async function testLlm(useKey: string) {
    const sel = llmForm.value[useKey];
    llmTesting.value[useKey] = true;
    delete llmResults.value[useKey];

    try {
        llmResults.value[useKey] = await testLlmConnection(
            sel.provider,
            sel.model,
            llmTestSchema.value[useKey] ?? false,
        );
    } catch (e: unknown) {
        llmResults.value[useKey] = {
            ok: false,
            latency_ms: 0,
            error: e instanceof Error ? e.message : '測試失敗',
        };
    } finally {
        llmTesting.value[useKey] = false;
    }
}

// ── Share Tokens ─────────────────────────────────────────

interface ShareToken {
    id: number;
    scope: string;
    max_uses: number | null;
    uses_count: number;
    note: string | null;
    expires_at: string | null;
    line_user_id: string | null;
    created_at: string;
}

const tokens = ref<ShareToken[]>([]);
const tokensLoading = ref(false);
const tokensError = ref('');

const scopes = computed(() => (page.props.shareTokenScopes as string[]) ?? []);

const tokenForm = ref({
    scope: '',
    max_uses: '' as string | number,
    note: '',
    expires_at: '',
});
watchEffect(() => {
    if (!tokenForm.value.scope && scopes.value.length) {
        tokenForm.value.scope = scopes.value[0];
    }
});
const isCreating = ref(false);
const createError = ref('');

const newRawToken = ref('');
const showTokenModal = ref(false);

const aboutUrl = computed(() => {
    if (!newRawToken.value) {
        return '';
    }

    return `${window.location.origin}${routes.about()}?t=${newRawToken.value}`;
});

async function fetchTokens() {
    tokensLoading.value = true;
    tokensError.value = '';

    try {
        const res = await fetch(api.admin.shareTokens(), {
            credentials: 'include',
        });

        if (!res.ok) {
            tokensError.value = '載入失敗';

            return;
        }

        tokens.value = await res.json();
    } catch {
        tokensError.value = '載入失敗';
    } finally {
        tokensLoading.value = false;
    }
}

async function createToken() {
    isCreating.value = true;
    createError.value = '';

    try {
        const body: Record<string, unknown> = { scope: tokenForm.value.scope };

        if (tokenForm.value.max_uses !== '') {
            body.max_uses = Number(tokenForm.value.max_uses);
        }

        if (tokenForm.value.note) {
            body.note = tokenForm.value.note;
        }

        if (tokenForm.value.expires_at) {
            body.expires_at = tokenForm.value.expires_at;
        }

        const res = await fetch(api.admin.shareTokens(), {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify(body),
        });
        const data = await res.json();

        if (!res.ok) {
            createError.value = data.message ?? '建立失敗';

            return;
        }

        newRawToken.value = data.raw_token;
        showTokenModal.value = true;
        tokenForm.value = {
            scope: scopes.value[0] ?? '',
            max_uses: '',
            note: '',
            expires_at: '',
        };
        await fetchTokens();
    } finally {
        isCreating.value = false;
    }
}

async function deleteToken(id: number) {
    if (!confirm('確定刪除此 token？刪除後持有連結的人將無法再使用。')) {
        return;
    }

    const res = await fetch(api.admin.shareTokenDestroy(id), {
        method: 'DELETE',
        credentials: 'include',
    });

    if (res.ok) {
        tokens.value = tokens.value.filter((t) => t.id !== id);
    }
}

function copyUrl() {
    navigator.clipboard.writeText(aboutUrl.value);
}

function formatDate(d: string | null) {
    if (!d) {
        return '—';
    }

    return new Date(d).toLocaleDateString('zh-TW');
}

// ── Micro Host ───────────────────────────────────────────

interface MicroVm {
    id: number;
    name: string;
    type: 'qemu' | 'lxc';
    status: 'running' | 'stopped' | 'paused' | 'unknown';
}

interface MicroHostStatus {
    status: 'online' | 'offline';
    host?: string;
    last_seen?: string;
    error?: string;
    vms?: MicroVm[];
    cts?: MicroVm[];
}

const microHost = ref<MicroHostStatus>({ status: 'offline' });
const microLoading = ref(false);
let microPollTimer: ReturnType<typeof setInterval> | null = null;

async function fetchMicroStatus() {
    microLoading.value = true;

    try {
        const res = await fetch(api.admin.microHostStatus(), {
            credentials: 'include',
        });
        microHost.value = await res.json();
    } catch {
        microHost.value = { status: 'offline' };
    } finally {
        microLoading.value = false;
    }
}

onMounted(() => {
    loadSettings();
    fetchTokens();
    fetchMicroStatus();
    microPollTimer = setInterval(fetchMicroStatus, 15_000);
});

onUnmounted(() => {
    if (microPollTimer !== null) {
        clearInterval(microPollTimer);
    }
});
</script>

<template>
    <Head title="系統管理" />
    <AppLayout>
        <div
            class="mx-auto w-full max-w-screen-2xl px-[18px] pt-8 pb-16 md:px-8"
        >
            <!-- Header -->
            <p
                class="binary-label mb-2 text-xs font-bold text-[var(--binary-primary)] uppercase"
            >
                &gt; admin_console
            </p>
            <h1
                class="binary-page-title mb-8 text-[var(--binary-text)] md:mb-12"
            >
                System
            </h1>

            <!-- Tab Card -->
            <div
                class="overflow-hidden rounded-none border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-container)] md:rounded-2xl"
            >
                <!-- Tab Bar -->
                <div
                    class="flex border-b border-[var(--binary-outline-variant)]"
                >
                    <button
                        v-for="tab in [
                            { key: 'settings', label: '設定' },
                            { key: 'llm', label: 'AI 模型' },
                            { key: 'tokens', label: '分享連結管理' },
                            { key: 'micro-host', label: '微型主機' },
                        ] as const"
                        :key="tab.key"
                        class="binary-label px-3 py-3 text-[11px] font-bold tracking-widest uppercase transition-colors md:px-6"
                        :class="
                            activeTab === tab.key
                                ? '-mb-px border-b-2 border-[var(--binary-primary)] text-[var(--binary-primary)]'
                                : 'text-[var(--binary-outline)] hover:text-[var(--binary-text)]'
                        "
                        @click="activeTab = tab.key"
                    >
                        <span class="flex items-center gap-1.5">
                            {{ tab.label }}
                            <span
                                v-if="tab.key === 'micro-host'"
                                class="inline-block h-1.5 w-1.5 rounded-full"
                                :class="
                                    microHost.status === 'online'
                                        ? 'bg-[var(--binary-primary)]'
                                        : 'bg-red-500'
                                "
                            />
                        </span>
                    </button>
                </div>

                <div class="px-4 py-6 md:px-10">
                    <!-- ── Settings Tab ── -->
                    <template v-if="activeTab === 'settings'">
                        <div
                            v-if="settingsLoading"
                            class="py-12 text-center text-sm text-[var(--binary-text-muted)]"
                        >
                            載入中...
                        </div>
                        <form
                            v-else
                            class="max-w-2xl space-y-6"
                            @submit.prevent="saveSettings"
                        >
                            <div class="space-y-6">
                                <div class="space-y-1.5">
                                    <label
                                        class="binary-label block text-[11px] font-bold text-[var(--binary-outline)] uppercase"
                                        >站台名稱</label
                                    >
                                    <input
                                        v-model="settingsForm.site_name"
                                        class="binary-input"
                                        type="text"
                                        :placeholder="defaultSiteName"
                                    />
                                </div>
                                <div class="space-y-1.5">
                                    <label
                                        class="binary-label block text-[11px] font-bold text-[var(--binary-outline)] uppercase"
                                        >最大登入失敗次數</label
                                    >
                                    <input
                                        v-model.number="
                                            settingsForm.max_login_attempts
                                        "
                                        class="binary-input"
                                        type="number"
                                        min="1"
                                        max="20"
                                    />
                                </div>
                                <div class="space-y-1.5">
                                    <label
                                        class="binary-label block text-[11px] font-bold text-[var(--binary-outline)] uppercase"
                                        >頭像尺寸</label
                                    >
                                    <select
                                        v-model.number="
                                            settingsForm.avatar_size
                                        "
                                        class="binary-input"
                                    >
                                        <option :value="64">64px</option>
                                        <option :value="128">128px</option>
                                        <option :value="256">256px</option>
                                    </select>
                                </div>
                                <div class="flex flex-col gap-4">
                                    <label
                                        class="flex cursor-pointer items-center gap-3"
                                    >
                                        <input
                                            v-model="
                                                settingsForm.allow_registration
                                            "
                                            type="checkbox"
                                            class="h-4 w-4 border-0 bg-[var(--binary-surface-high)] text-[var(--binary-primary-container)] focus:ring-0"
                                        />
                                        <span
                                            class="binary-label text-xs text-[var(--binary-text)] uppercase"
                                            >開放使用者自行註冊</span
                                        >
                                    </label>
                                    <label
                                        class="flex cursor-pointer items-center gap-3"
                                    >
                                        <input
                                            v-model="
                                                settingsForm.maintenance_mode
                                            "
                                            type="checkbox"
                                            class="h-4 w-4 border-0 bg-[var(--binary-surface-high)] text-[var(--binary-primary-container)] focus:ring-0"
                                        />
                                        <span
                                            class="binary-label text-xs text-[var(--binary-text)] uppercase"
                                            >維護模式</span
                                        >
                                    </label>
                                </div>
                            </div>
                            <p
                                v-if="settingsError"
                                class="border border-red-400/20 bg-red-950/20 px-4 py-3 text-sm text-red-200"
                            >
                                {{ settingsError }}
                            </p>
                            <p
                                v-if="settingsSuccess"
                                class="border border-[var(--binary-primary-container)]/20 bg-[var(--binary-primary-container)]/10 px-4 py-3 text-sm text-[var(--binary-primary)]"
                            >
                                {{ settingsSuccess }}
                            </p>
                            <div class="flex justify-end">
                                <button
                                    class="binary-button"
                                    :disabled="isSaving"
                                    type="submit"
                                >
                                    {{ isSaving ? '儲存中...' : '儲存設定' }}
                                    <span aria-hidden="true">-></span>
                                </button>
                            </div>
                        </form>
                    </template>

                    <!-- ── LLM 模型 Tab ── -->
                    <template v-else-if="activeTab === 'llm'">
                        <div class="max-w-3xl space-y-5">
                            <p
                                class="binary-label text-[11px] font-bold tracking-widest text-[var(--binary-outline)] uppercase"
                            >
                                &gt; 各用途的 LLM provider /
                                model（儲存後即時生效）
                            </p>

                            <div
                                v-for="use in LLM_USES"
                                :key="use.key"
                                class="space-y-3 rounded-none border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-high)] p-5 md:rounded-xl"
                            >
                                <div class="flex items-center justify-between">
                                    <span
                                        class="binary-label text-xs font-bold text-[var(--binary-text)] uppercase"
                                        >{{ use.label }}</span
                                    >
                                    <code
                                        class="text-[10px] text-[var(--binary-outline)]"
                                        >{{ use.key }}</code
                                    >
                                </div>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <select
                                        v-model="llmForm[use.key].provider"
                                        class="binary-input"
                                        @change="onProviderChange(use.key)"
                                    >
                                        <option
                                            v-for="p in llmProviders"
                                            :key="p"
                                            :value="p"
                                        >
                                            {{ p }}
                                        </option>
                                    </select>
                                    <select
                                        v-model="llmForm[use.key].model"
                                        class="binary-input"
                                    >
                                        <option
                                            v-for="m in modelsFor(
                                                llmForm[use.key].provider,
                                            )"
                                            :key="m"
                                            :value="m"
                                        >
                                            {{ m }}
                                        </option>
                                    </select>
                                </div>

                                <div class="flex flex-wrap items-center gap-4">
                                    <button
                                        type="button"
                                        class="binary-ghost-button px-3 py-1.5 text-xs"
                                        :disabled="llmTesting[use.key]"
                                        @click="testLlm(use.key)"
                                    >
                                        {{
                                            llmTesting[use.key]
                                                ? '測試中...'
                                                : '測試'
                                        }}
                                    </button>
                                    <label
                                        class="flex cursor-pointer items-center gap-2 text-[11px] text-[var(--binary-outline)]"
                                    >
                                        <input
                                            v-model="llmTestSchema[use.key]"
                                            type="checkbox"
                                            class="h-3.5 w-3.5 border-0 bg-[var(--binary-surface-highest)] text-[var(--binary-primary-container)] focus:ring-0"
                                        />
                                        測 JSON 輸出
                                    </label>
                                    <span
                                        v-if="llmResults[use.key]"
                                        class="text-xs"
                                        :class="
                                            llmResults[use.key].ok
                                                ? 'text-[var(--binary-primary)]'
                                                : 'text-red-400'
                                        "
                                    >
                                        <template v-if="llmResults[use.key].ok">
                                            ✓
                                            {{
                                                llmResults[use.key].latency_ms
                                            }}ms ·
                                            {{ llmResults[use.key].reply }}
                                        </template>
                                        <template v-else>
                                            ✗ {{ llmResults[use.key].error }}
                                        </template>
                                    </span>
                                </div>
                            </div>

                            <p
                                v-if="llmSaveErr"
                                class="border border-red-400/20 bg-red-950/20 px-4 py-3 text-sm text-red-200"
                            >
                                {{ llmSaveErr }}
                            </p>
                            <p
                                v-if="llmSaveMsg"
                                class="border border-[var(--binary-primary-container)]/20 bg-[var(--binary-primary-container)]/10 px-4 py-3 text-sm text-[var(--binary-primary)]"
                            >
                                {{ llmSaveMsg }}
                            </p>

                            <div class="flex justify-end">
                                <button
                                    class="binary-button"
                                    :disabled="llmSaving"
                                    @click="saveLlm"
                                >
                                    {{
                                        llmSaving ? '儲存中...' : '儲存模型設定'
                                    }}
                                    <span aria-hidden="true">-></span>
                                </button>
                            </div>
                        </div>
                    </template>

                    <!-- ── Share Tokens Tab ── -->
                    <template v-else-if="activeTab === 'tokens'">
                        <!-- 建立表單 -->
                        <div class="mb-8">
                            <h2
                                class="binary-label mb-4 text-[11px] font-bold tracking-widest text-[var(--binary-outline)] uppercase"
                            >
                                &gt; 建立新分享連結
                            </h2>
                            <div class="grid gap-4 sm:grid-cols-4">
                                <div class="space-y-1.5">
                                    <label
                                        class="binary-label block text-[11px] font-bold text-[var(--binary-outline)] uppercase"
                                        >Scope</label
                                    >
                                    <select
                                        v-model="tokenForm.scope"
                                        class="binary-input w-full"
                                    >
                                        <option
                                            v-for="s in scopes"
                                            :key="s"
                                            :value="s"
                                        >
                                            {{ s }}
                                        </option>
                                    </select>
                                </div>
                                <div class="space-y-1.5">
                                    <label
                                        class="binary-label block text-[11px] font-bold text-[var(--binary-outline)] uppercase"
                                        >使用次數上限</label
                                    >
                                    <input
                                        v-model="tokenForm.max_uses"
                                        type="number"
                                        min="1"
                                        placeholder="留空 = 無限"
                                        class="binary-input w-full"
                                    />
                                </div>
                                <div class="space-y-1.5">
                                    <label
                                        class="binary-label block text-[11px] font-bold text-[var(--binary-outline)] uppercase"
                                        >備註（管理用）</label
                                    >
                                    <input
                                        v-model="tokenForm.note"
                                        type="text"
                                        maxlength="255"
                                        placeholder="例：給某面試官"
                                        class="binary-input w-full"
                                    />
                                </div>
                                <div class="space-y-1.5">
                                    <label
                                        class="binary-label block text-[11px] font-bold text-[var(--binary-outline)] uppercase"
                                        >到期日（選填）</label
                                    >
                                    <input
                                        v-model="tokenForm.expires_at"
                                        type="date"
                                        class="binary-input w-full"
                                    />
                                </div>
                            </div>
                            <p
                                v-if="createError"
                                class="mt-3 text-xs text-red-400"
                            >
                                {{ createError }}
                            </p>
                            <div class="mt-4 flex justify-end">
                                <button
                                    class="binary-button"
                                    :disabled="isCreating"
                                    @click="createToken"
                                >
                                    {{
                                        isCreating ? '建立中...' : '建立 Token'
                                    }}
                                </button>
                            </div>
                        </div>

                        <!-- Token 列表 -->
                        <div
                            v-if="tokensLoading"
                            class="text-xs text-[var(--binary-outline)] opacity-50"
                        >
                            載入中...
                        </div>
                        <p v-else-if="tokensError" class="text-xs text-red-400">
                            {{ tokensError }}
                        </p>
                        <p
                            v-else-if="tokens.length === 0"
                            class="text-xs text-[var(--binary-outline)]"
                        >
                            尚無 token
                        </p>
                        <div v-else class="space-y-3">
                            <div
                                v-for="token in tokens"
                                :key="token.id"
                                class="flex flex-wrap items-start justify-between gap-4 rounded-none border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-high)] px-5 py-4 md:rounded-xl"
                            >
                                <div class="min-w-0 flex-1 space-y-1 text-sm">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span
                                            class="rounded border border-[var(--binary-primary)]/40 px-2 py-0.5 text-[10px] font-bold text-[var(--binary-primary)] uppercase"
                                            >{{ token.scope }}</span
                                        >
                                        <span
                                            v-if="token.line_user_id"
                                            class="rounded border border-green-500/40 px-2 py-0.5 text-[10px] font-bold text-green-400 uppercase"
                                            >LINE</span
                                        >
                                        <span
                                            class="font-medium text-[var(--binary-text)]"
                                            >{{
                                                token.note ?? '（無備註）'
                                            }}</span
                                        >
                                    </div>
                                    <div
                                        class="flex flex-wrap gap-4 text-xs text-[var(--binary-outline)]"
                                    >
                                        <span
                                            >使用：{{ token.uses_count }} /
                                            {{ token.max_uses ?? '∞' }}</span
                                        >
                                        <span
                                            >到期：{{
                                                formatDate(token.expires_at)
                                            }}</span
                                        >
                                        <span
                                            >建立：{{
                                                formatDate(token.created_at)
                                            }}</span
                                        >
                                    </div>
                                </div>
                                <button
                                    class="shrink-0 rounded border border-red-500/30 px-3 py-1.5 text-xs font-medium text-red-400 transition-colors hover:bg-red-500/10"
                                    @click="deleteToken(token.id)"
                                >
                                    刪除
                                </button>
                            </div>
                        </div>
                    </template>

                    <!-- ── Micro Host Tab ── -->
                    <template v-else-if="activeTab === 'micro-host'">
                        <div class="max-w-2xl space-y-6">
                            <p
                                class="binary-label text-[11px] font-bold tracking-widest text-[var(--binary-outline)] uppercase"
                            >
                                &gt; 微型主機狀態（每 15 秒自動更新）
                            </p>

                            <!-- Status Card -->
                            <div
                                class="flex items-center gap-5 rounded-none border bg-[var(--binary-surface-high)] px-6 py-5 md:rounded-xl"
                                :class="
                                    microHost.status === 'online'
                                        ? 'border-[var(--binary-primary)]/40'
                                        : 'border-[var(--binary-outline-variant)]'
                                "
                            >
                                <span
                                    class="inline-block h-3 w-3 shrink-0 rounded-full"
                                    :class="
                                        microHost.status === 'online'
                                            ? 'bg-[var(--binary-primary)] shadow-[0_0_8px_var(--binary-primary)]'
                                            : 'bg-red-500/60'
                                    "
                                />
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="text-sm font-semibold"
                                        :class="
                                            microHost.status === 'online'
                                                ? 'text-[var(--binary-primary)]'
                                                : 'text-[var(--binary-outline)]'
                                        "
                                    >
                                        {{
                                            microHost.status === 'online'
                                                ? 'ONLINE'
                                                : 'OFFLINE'
                                        }}
                                    </p>
                                    <p
                                        v-if="microHost.host"
                                        class="mt-0.5 truncate text-xs text-[var(--binary-text-muted)]"
                                    >
                                        {{ microHost.host }}
                                    </p>
                                    <p
                                        v-if="microHost.last_seen"
                                        class="mt-0.5 text-xs text-[var(--binary-outline)]"
                                    >
                                        last seen：{{ microHost.last_seen }}
                                    </p>
                                </div>
                                <button
                                    class="binary-ghost-button shrink-0 px-3 py-1.5 text-xs"
                                    :disabled="microLoading"
                                    @click="fetchMicroStatus"
                                >
                                    {{ microLoading ? '...' : '重整' }}
                                </button>
                            </div>

                            <!-- VM / CT List -->
                            <template
                                v-if="
                                    microHost.status === 'online' &&
                                    ((microHost.vms?.length ?? 0) > 0 ||
                                        (microHost.cts?.length ?? 0) > 0)
                                "
                            >
                                <div
                                    v-for="group in [
                                        {
                                            label: 'VM (QEMU)',
                                            items: microHost.vms,
                                        },
                                        {
                                            label: 'CT (LXC)',
                                            items: microHost.cts,
                                        },
                                    ]"
                                    :key="group.label"
                                >
                                    <template v-if="group.items?.length">
                                        <p
                                            class="binary-label mb-2 text-[10px] font-bold tracking-widest text-[var(--binary-outline)] uppercase"
                                        >
                                            {{ group.label }}
                                        </p>
                                        <div class="space-y-2">
                                            <div
                                                v-for="vm in group.items"
                                                :key="vm.id"
                                                class="flex items-center gap-3 rounded-none border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-container)] px-4 py-3 md:rounded-lg"
                                            >
                                                <span
                                                    class="inline-block h-2 w-2 shrink-0 rounded-full"
                                                    :class="
                                                        vm.status === 'running'
                                                            ? 'bg-[var(--binary-primary)]'
                                                            : 'bg-[var(--binary-outline)]/40'
                                                    "
                                                />
                                                <span
                                                    class="w-10 shrink-0 text-right font-mono text-[10px] text-[var(--binary-outline)]"
                                                    >{{ vm.id }}</span
                                                >
                                                <span
                                                    class="min-w-0 flex-1 truncate text-sm text-[var(--binary-text)]"
                                                    >{{ vm.name }}</span
                                                >
                                                <span
                                                    class="binary-label shrink-0 text-[10px] uppercase"
                                                    :class="
                                                        vm.status === 'running'
                                                            ? 'text-[var(--binary-primary)]'
                                                            : 'text-[var(--binary-outline)]'
                                                    "
                                                    >{{ vm.status }}</span
                                                >
                                                <!-- 啟動鍵（暫隱） -->
                                                <button
                                                    class="binary-ghost-button hidden shrink-0 px-2 py-1 text-[10px]"
                                                >
                                                    start
                                                </button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Raw Token Modal -->
        <Teleport to="body">
            <div
                v-if="showTokenModal"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm"
                @click.self="showTokenModal = false"
            >
                <div
                    class="w-full max-w-lg rounded-none border border-[var(--binary-primary)]/30 bg-[var(--binary-surface)] p-6 shadow-2xl md:rounded-2xl md:p-8"
                >
                    <h3
                        class="binary-label mb-1 text-xs font-bold tracking-widest text-[var(--binary-primary)] uppercase"
                    >
                        &gt; 分享連結已建立
                    </h3>
                    <p class="mb-4 text-xs text-[var(--binary-outline)]">
                        此連結只顯示一次，請複製後妥善保管。
                    </p>
                    <div
                        class="mb-4 rounded-lg bg-[var(--binary-surface-container)] p-3 font-mono text-xs break-all text-[var(--binary-text)] select-all"
                    >
                        {{ aboutUrl }}
                    </div>
                    <div class="flex justify-end gap-3">
                        <button
                            class="binary-ghost-button px-4 py-2 text-xs"
                            @click="copyUrl"
                        >
                            複製連結
                        </button>
                        <button
                            class="binary-button"
                            @click="showTokenModal = false"
                        >
                            關閉
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

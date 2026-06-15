<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted, watch, watchEffect } from 'vue';
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

type Tab = 'settings' | 'tokens' | 'llm' | 'micro-host' | 'gacha';
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
    api_error?: string;
    vms?: MicroVm[];
    cts?: MicroVm[];
}

const microHost = ref<MicroHostStatus>({ status: 'offline' });
const microLoading = ref(false);
let microPollTimer: ReturnType<typeof setInterval> | null = null;

async function fetchMicroStatus() {
    if (microLoading.value) {
        return;
    }

    microLoading.value = true;

    try {
        const res = await fetch(api.admin.microHostStatus(), {
            credentials: 'include',
            headers: { Accept: 'application/json' },
        });

        if (!res.ok) {
            throw new Error('http_error');
        }

        microHost.value = await res.json();
    } catch {
        microHost.value = { status: 'offline' };
    } finally {
        microLoading.value = false;
    }
}

function startMicroPolling() {
    fetchMicroStatus();

    if (microPollTimer === null) {
        microPollTimer = setInterval(fetchMicroStatus, 15_000);
    }
}

function stopMicroPolling() {
    if (microPollTimer !== null) {
        clearInterval(microPollTimer);
        microPollTimer = null;
    }
}

// ── Gacha 卡牌 / 卡組管理 ─────────────────────────────────

interface GachaCard {
    id: number;
    name: string;
    rarity: string;
    weight: number;
}

interface GachaDeck {
    id: number;
    name: string;
    cards: { id: number; name: string; rarity: string; weight: number }[];
}

const gachaCards = ref<GachaCard[]>([]);
const gachaDecks = ref<GachaDeck[]>([]);
const gachaLoading = ref(false);

const newCardName = ref('');
const newCardRarity = ref<GachaCard['rarity']>('common');
const newCardWeight = ref(100);
const cardSaving = ref(false);
const newCardImageFile = ref<File | null>(null);
const newCardImagePreview = ref('');
const cardDragOver = ref(false);

function onCardImageDrop(e: DragEvent) {
    cardDragOver.value = false;
    const file = e.dataTransfer?.files[0];

    if (file?.type.startsWith('image/')) {
        if (newCardImagePreview.value) {
            URL.revokeObjectURL(newCardImagePreview.value);
        }

        newCardImageFile.value = file;
        newCardImagePreview.value = URL.createObjectURL(file);
    }
}

function onCardImageChange(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];

    if (file) {
        if (newCardImagePreview.value) {
            URL.revokeObjectURL(newCardImagePreview.value);
        }

        newCardImageFile.value = file;
        newCardImagePreview.value = URL.createObjectURL(file);
    }
}

function clearCardImage() {
    if (newCardImagePreview.value) {
        URL.revokeObjectURL(newCardImagePreview.value);
    }

    newCardImageFile.value = null;
    newCardImagePreview.value = '';
}

const newDeckName = ref('');
const newDeckCardIds = ref<number[]>([]);
const deckSaving = ref(false);

async function fetchGachaData() {
    if (gachaLoading.value) {
        return;
    }

    gachaLoading.value = true;

    const [cardsRes, decksRes] = await Promise.all([
        fetch(api.gacha.cards(), { credentials: 'include' }).catch(() => null),
        fetch(api.gacha.decks(), { credentials: 'include' }).catch(() => null),
    ]);

    if (cardsRes?.ok) {
        gachaCards.value = await cardsRes.json();
    }

    if (decksRes?.ok) {
        gachaDecks.value = await decksRes.json();
    }

    gachaLoading.value = false;
}

async function createCard() {
    if (!newCardName.value.trim() || cardSaving.value) {
        return;
    }

    cardSaving.value = true;

    const res = await fetch(api.gacha.cardStore(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({
            name: newCardName.value.trim(),
            rarity: newCardRarity.value,
            weight: newCardWeight.value,
        }),
    }).catch(() => null);
    cardSaving.value = false;

    if (!res?.ok) {
        return;
    }

    gachaCards.value.push(await res.json());
    newCardName.value = '';
    newCardWeight.value = 100;
    clearCardImage();
}

async function deleteCard(id: number) {
    if (!confirm('確定刪除此卡牌？相關卡組也會移除此卡牌。')) {
        return;
    }

    const res = await fetch(api.gacha.cardDestroy(id), {
        method: 'DELETE',
        credentials: 'include',
    }).catch(() => null);

    if (res?.ok) {
        gachaCards.value = gachaCards.value.filter((c) => c.id !== id);
    }
}

async function createDeck() {
    if (!newDeckName.value.trim() || deckSaving.value) {
        return;
    }

    deckSaving.value = true;

    const res = await fetch(api.gacha.deckStore(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({
            name: newDeckName.value.trim(),
            card_ids: newDeckCardIds.value,
        }),
    }).catch(() => null);
    deckSaving.value = false;

    if (!res?.ok) {
        return;
    }

    gachaDecks.value.push(await res.json());
    newDeckName.value = '';
    newDeckCardIds.value = [];
}

async function deleteDeck(id: number) {
    if (!confirm('確定刪除此卡組？')) {
        return;
    }

    const res = await fetch(api.gacha.deckDestroy(id), {
        method: 'DELETE',
        credentials: 'include',
    }).catch(() => null);

    if (res?.ok) {
        gachaDecks.value = gachaDecks.value.filter((d) => d.id !== id);
    }
}

function toggleNewDeckCard(id: number) {
    const idx = newDeckCardIds.value.indexOf(id);

    if (idx === -1) {
        newDeckCardIds.value.push(id);
    } else {
        newDeckCardIds.value.splice(idx, 1);
    }
}

// 只在「微型主機」tab 開啟時輪詢，離開即停止
watch(activeTab, (tab) => {
    if (tab === 'micro-host') {
        startMicroPolling();
    } else {
        stopMicroPolling();
    }

    if (tab === 'gacha') {
        fetchGachaData();
    }
});

onMounted(() => {
    loadSettings();
    fetchTokens();
});

onUnmounted(stopMicroPolling);
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
                            { key: 'gacha', label: 'Gacha' },
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
                        {{ tab.label }}
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

                    <!-- ── Gacha Tab ── -->
                    <template v-else-if="activeTab === 'gacha'">
                        <div
                            v-if="gachaLoading"
                            class="py-12 text-center text-sm text-[var(--binary-text-muted)]"
                        >
                            載入中...
                        </div>
                        <div v-else class="grid gap-10 md:grid-cols-2">
                            <!-- 卡牌管理 -->
                            <div>
                                <h2
                                    class="binary-label mb-4 text-[11px] font-bold tracking-widest text-[var(--binary-outline)] uppercase"
                                >
                                    &gt; 卡牌管理
                                </h2>

                                <!-- 卡牌列表 -->
                                <div
                                    v-if="gachaCards.length > 0"
                                    class="mb-4 flex max-h-72 flex-col gap-1 overflow-y-auto"
                                >
                                    <div
                                        v-for="card in gachaCards"
                                        :key="card.id"
                                        class="flex items-center gap-3 rounded-lg px-3 py-2 hover:bg-[var(--binary-surface-high)]"
                                    >
                                        <span
                                            class="flex-1 text-xs text-[var(--binary-text)]"
                                            >{{ card.name }}</span
                                        >
                                        <span
                                            class="text-[10px] font-bold uppercase"
                                            :style="{
                                                color: `var(--rarity-${card.rarity})`,
                                            }"
                                            >{{ card.rarity }}</span
                                        >
                                        <span
                                            class="w-10 text-right text-[10px] text-[var(--binary-text-muted)]"
                                            >×{{ card.weight }}</span
                                        >
                                        <button
                                            class="ml-1 text-[10px] text-[var(--binary-tertiary)]/60 transition-colors hover:text-[var(--binary-tertiary)]"
                                            @click="deleteCard(card.id)"
                                        >
                                            ✕
                                        </button>
                                    </div>
                                </div>
                                <p
                                    v-else
                                    class="mb-4 text-xs text-[var(--binary-text-muted)]"
                                >
                                    尚無卡牌
                                </p>

                                <!-- 新增卡牌 -->
                                <div class="space-y-3">
                                    <input
                                        v-model="newCardName"
                                        type="text"
                                        maxlength="50"
                                        placeholder="卡牌名稱"
                                        class="binary-input w-full"
                                    />
                                    <div class="flex gap-2">
                                        <select
                                            v-model="newCardRarity"
                                            class="binary-input flex-1"
                                        >
                                            <option value="common">
                                                Common
                                            </option>
                                            <option value="rare">Rare</option>
                                            <option value="epic">Epic</option>
                                            <option value="legendary">
                                                Legendary
                                            </option>
                                        </select>
                                        <input
                                            v-model.number="newCardWeight"
                                            type="number"
                                            min="1"
                                            max="9999"
                                            placeholder="權重"
                                            class="binary-input w-24"
                                        />
                                    </div>
                                    <!-- 圖片上傳（UI 預留，尚未接後端） -->
                                    <div
                                        class="relative flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed transition-colors"
                                        :class="
                                            cardDragOver
                                                ? 'border-[var(--binary-primary)] bg-[var(--binary-primary)]/5'
                                                : 'border-[var(--binary-outline-variant)] hover:border-[var(--binary-outline)]'
                                        "
                                        style="min-height: 96px"
                                        @dragover.prevent="cardDragOver = true"
                                        @dragleave="cardDragOver = false"
                                        @drop.prevent="onCardImageDrop"
                                        @click="
                                            (
                                                $refs.cardImageInput as HTMLInputElement
                                            )?.click()
                                        "
                                    >
                                        <input
                                            ref="cardImageInput"
                                            type="file"
                                            accept="image/*"
                                            class="hidden"
                                            @change="onCardImageChange"
                                        />
                                        <template v-if="newCardImagePreview">
                                            <img
                                                :src="newCardImagePreview"
                                                class="h-20 w-20 rounded-md object-cover"
                                            />
                                            <button
                                                class="absolute top-1.5 right-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-black/50 text-[10px] text-white hover:bg-red-500/70"
                                                @click.stop="clearCardImage"
                                            >
                                                ✕
                                            </button>
                                        </template>
                                        <template v-else>
                                            <span
                                                class="text-[11px] text-[var(--binary-outline)]"
                                                >拖曳或點擊上傳圖片</span
                                            >
                                            <span
                                                class="text-[10px] text-[var(--binary-outline)]/50"
                                                >（尚未接後端）</span
                                            >
                                        </template>
                                    </div>

                                    <div class="flex justify-end">
                                        <button
                                            class="binary-button"
                                            :disabled="
                                                !newCardName.trim() ||
                                                cardSaving
                                            "
                                            @click="createCard"
                                        >
                                            {{
                                                cardSaving
                                                    ? '新增中...'
                                                    : '新增卡牌'
                                            }}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- 卡組管理 -->
                            <div>
                                <h2
                                    class="binary-label mb-4 text-[11px] font-bold tracking-widest text-[var(--binary-outline)] uppercase"
                                >
                                    &gt; 卡組管理
                                </h2>

                                <!-- 卡組列表 -->
                                <div
                                    v-if="gachaDecks.length > 0"
                                    class="mb-4 flex flex-col gap-2"
                                >
                                    <div
                                        v-for="deck in gachaDecks"
                                        :key="deck.id"
                                        class="rounded-lg border border-[var(--binary-outline-variant)] px-4 py-3"
                                    >
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="flex-1 text-sm font-medium text-[var(--binary-text)]"
                                                >{{ deck.name }}</span
                                            >
                                            <span
                                                class="text-[10px] text-[var(--binary-text-muted)]"
                                                >{{
                                                    deck.cards?.length ?? 0
                                                }}
                                                張</span
                                            >
                                            <button
                                                class="text-[10px] text-[var(--binary-tertiary)]/60 transition-colors hover:text-[var(--binary-tertiary)]"
                                                @click="deleteDeck(deck.id)"
                                            >
                                                ✕
                                            </button>
                                        </div>
                                        <div
                                            v-if="deck.cards?.length"
                                            class="mt-1.5 flex flex-wrap gap-1"
                                        >
                                            <span
                                                v-for="c in deck.cards"
                                                :key="c.id"
                                                class="rounded px-1.5 py-0.5 text-[10px] font-bold uppercase"
                                                :style="{
                                                    color: `var(--rarity-${c.rarity})`,
                                                    borderColor: `color-mix(in srgb, var(--rarity-${c.rarity}) 25%, transparent)`,
                                                    borderWidth: '1px',
                                                    borderStyle: 'solid',
                                                }"
                                                >{{ c.name }}</span
                                            >
                                        </div>
                                    </div>
                                </div>
                                <p
                                    v-else
                                    class="mb-4 text-xs text-[var(--binary-text-muted)]"
                                >
                                    尚無卡組
                                </p>

                                <!-- 新增卡組 -->
                                <div class="space-y-3">
                                    <input
                                        v-model="newDeckName"
                                        type="text"
                                        maxlength="50"
                                        placeholder="卡組名稱"
                                        class="binary-input w-full"
                                    />
                                    <div
                                        v-if="gachaCards.length > 0"
                                        class="flex max-h-48 flex-col gap-1 overflow-y-auto rounded-lg border border-[var(--binary-outline-variant)] p-2"
                                    >
                                        <label
                                            v-for="card in gachaCards"
                                            :key="card.id"
                                            class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 hover:bg-[var(--binary-surface-high)]"
                                        >
                                            <input
                                                type="checkbox"
                                                class="accent-[var(--binary-primary)]"
                                                :checked="
                                                    newDeckCardIds.includes(
                                                        card.id,
                                                    )
                                                "
                                                @change="
                                                    toggleNewDeckCard(card.id)
                                                "
                                            />
                                            <span
                                                class="flex-1 text-xs text-[var(--binary-text)]"
                                                >{{ card.name }}</span
                                            >
                                            <span
                                                class="text-[10px] font-bold uppercase"
                                                :style="{
                                                    color: `var(--rarity-${card.rarity})`,
                                                }"
                                                >{{ card.rarity }}</span
                                            >
                                        </label>
                                    </div>
                                    <p
                                        v-else
                                        class="text-[11px] text-[var(--binary-text-muted)]"
                                    >
                                        請先新增卡牌再建立卡組
                                    </p>
                                    <div class="flex justify-end">
                                        <button
                                            class="binary-button"
                                            :disabled="
                                                !newDeckName.trim() ||
                                                deckSaving
                                            "
                                            @click="createDeck"
                                        >
                                            {{
                                                deckSaving
                                                    ? '建立中...'
                                                    : '建立卡組'
                                            }}
                                        </button>
                                    </div>
                                </div>
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

                            <!-- 主機面板（第一層：主機 / 第二層：其下的 VM、CT） -->
                            <div
                                v-if="microHost.status === 'online'"
                                class="overflow-hidden rounded-none border border-[var(--binary-primary)]/40 bg-[var(--binary-surface-high)] md:rounded-xl"
                            >
                                <!-- 第一層：主機 -->
                                <div class="flex items-center gap-5 px-6 py-5">
                                    <span
                                        class="inline-block h-3 w-3 shrink-0 rounded-full"
                                        :class="
                                            microHost.api_error
                                                ? 'bg-amber-400 shadow-[0_0_8px_theme(colors.amber.400)]'
                                                : 'bg-[var(--binary-primary)] shadow-[0_0_8px_var(--binary-primary)]'
                                        "
                                    />
                                    <div class="min-w-0 flex-1">
                                        <p
                                            class="text-sm font-semibold"
                                            :class="
                                                microHost.api_error
                                                    ? 'text-amber-400'
                                                    : 'text-[var(--binary-primary)]'
                                            "
                                        >
                                            {{ microHost.host || 'HOST' }}
                                        </p>
                                        <p
                                            class="binary-label mt-0.5 text-[10px] tracking-widest uppercase"
                                            :class="
                                                microHost.api_error
                                                    ? 'text-amber-400/70'
                                                    : 'text-[var(--binary-outline)]'
                                            "
                                        >
                                            {{
                                                microHost.api_error
                                                    ? 'API ERROR · ' +
                                                      microHost.api_error
                                                    : 'ONLINE'
                                            }}
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

                                <!-- 第二層：主機底下的 VM / CT -->
                                <div
                                    v-if="
                                        (microHost.vms?.length ?? 0) > 0 ||
                                        (microHost.cts?.length ?? 0) > 0
                                    "
                                    class="space-y-4 border-t border-[var(--binary-outline-variant)] bg-[var(--binary-surface-container)] px-4 py-4 md:px-6"
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
                                            <!-- 縮排 + 左側導引線，呈現從屬於主機 -->
                                            <div
                                                class="space-y-2 border-l border-[var(--binary-outline-variant)] pl-3 md:pl-4"
                                            >
                                                <div
                                                    v-for="vm in group.items"
                                                    :key="vm.id"
                                                    class="flex items-center gap-3 rounded-none border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-high)] px-4 py-3 md:rounded-lg"
                                                >
                                                    <span
                                                        class="inline-block h-2 w-2 shrink-0 rounded-full"
                                                        :class="
                                                            vm.status ===
                                                            'running'
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
                                                            vm.status ===
                                                            'running'
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
                                </div>
                            </div>

                            <!-- 無主機上線（拿不到 key）：不顯示燈號 -->
                            <div
                                v-else
                                class="flex items-center gap-5 rounded-none border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-high)] px-6 py-5 md:rounded-xl"
                            >
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="text-sm text-[var(--binary-text-muted)]"
                                    >
                                        沒有主機上線
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

<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted, watchEffect } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    AdminApiError,
    fetchAdminSettings,
    saveAdminSettings,
} from '@/lib/admin-api';
import type { AdminSettings } from '@/lib/admin-api';
import { api, routes } from '@/lib/routes';

type Tab = 'settings' | 'tokens';
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

onMounted(() => {
    loadSettings();
    fetchTokens();
});
</script>

<template>
    <Head title="系統管理" />
    <AppLayout>
        <div
            class="mx-auto w-full max-w-screen-2xl px-6 pb-16 md:px-8"
            style="padding-top: 6rem"
        >
            <!-- Header -->
            <p
                class="binary-label mb-2 text-xs font-bold text-[var(--binary-primary)] uppercase"
            >
                &gt; admin_console
            </p>
            <h1
                class="binary-display mb-12 text-4xl font-black tracking-tight text-[var(--binary-text)] uppercase md:text-6xl"
            >
                System
            </h1>

            <!-- Tab Card -->
            <div
                class="overflow-hidden rounded-2xl border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-container)]"
            >
                <!-- Tab Bar -->
                <div
                    class="flex border-b border-[var(--binary-outline-variant)]"
                >
                    <button
                        v-for="tab in [
                            { key: 'settings', label: '設定' },
                            { key: 'tokens', label: '分享連結管理' },
                        ] as const"
                        :key="tab.key"
                        class="binary-label px-6 py-3 text-[11px] font-bold tracking-widest uppercase transition-colors"
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

                <div class="px-10 py-6">
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
                                class="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-high)] px-5 py-4"
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
                    class="w-full max-w-lg rounded-2xl border border-[var(--binary-primary)]/30 bg-[var(--binary-surface)] p-8 shadow-2xl"
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

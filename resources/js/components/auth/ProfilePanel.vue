<script setup lang="ts">
import { usePage, router } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

import { useAuth } from '../../composables/useAuth';
import { AuthApiError, changePasswordWithApi } from '../../lib/auth-api';
import { encryptPassword } from '../../lib/crypto';
import { api, routes } from '../../lib/routes';

const { t } = useI18n();
const { user, isAdmin } = useAuth();

const activeTab = ref<'name' | 'password' | 'apikey'>('name');

// ── API-KEY ──────────────────────────────────────────────────
interface ApiKeyScopeOption {
    value: string;
    adminOnly: boolean;
}
const page = usePage();
const apiKeyScopeOptions = computed(
    () => (page.props.apiKeyScopes as ApiKeyScopeOption[]) ?? [],
);

interface ApiKey {
    id: number;
    name: string;
    scopes: string[] | null;
    revoked_at: string | null;
    created_at: string;
}

const apiKeys = ref<ApiKey[]>([]);
const apiKeyLoading = ref(false);
const apiKeyError = ref('');
const newApiKey = ref('');
const newApiKeyId = ref<number | null>(null);
const newApiKeyLoading = ref(false);
const newKeyName = ref('api-key');
const newKeyScopes = ref<string[]>([]);
const showCreateModal = ref(false);
const copied = ref(false);

function openCreateModal() {
    newKeyName.value = 'api-key';
    newKeyScopes.value = [];
    newApiKey.value = '';
    newApiKeyId.value = null;
    apiKeyError.value = '';
    showCreateModal.value = true;
}
function closeCreateModal() {
    showCreateModal.value = false;
}

async function fetchApiKeys() {
    apiKeyLoading.value = true;
    apiKeyError.value = '';

    try {
        const res = await fetch(api.userApiKeys.index(), {
            credentials: 'include',
        });
        apiKeys.value = await res.json();
    } catch {
        apiKeyError.value = t('profile.apikey_fetch_failed');
    } finally {
        apiKeyLoading.value = false;
    }
}

function bufferToPem(buffer: ArrayBuffer, label: string): string {
    const bytes = new Uint8Array(buffer);
    let str = '';

    for (let i = 0; i < bytes.length; i++) {
        str += String.fromCharCode(bytes[i]);
    }

    const b64 = btoa(str).replace(/(.{64})/g, '$1\n');

    return `-----BEGIN ${label}-----\n${b64}\n-----END ${label}-----`;
}

async function generateKeyPair(): Promise<{
    publicKeyPem: string;
    privateKey: CryptoKey;
}> {
    const keyPair = await window.crypto.subtle.generateKey(
        {
            name: 'RSA-OAEP',
            modulusLength: 2048,
            publicExponent: new Uint8Array([1, 0, 1]),
            hash: 'SHA-1',
        },
        false,
        ['encrypt', 'decrypt'],
    );
    const spki = await window.crypto.subtle.exportKey(
        'spki',
        keyPair.publicKey,
    );

    return {
        publicKeyPem: bufferToPem(spki, 'PUBLIC KEY'),
        privateKey: keyPair.privateKey,
    };
}

async function createApiKey() {
    if (!newKeyScopes.value.length) {
        apiKeyError.value = '請選擇至少一個 Scope';

        return;
    }

    newApiKeyLoading.value = true;
    newApiKey.value = '';
    newApiKeyId.value = null;
    apiKeyError.value = '';

    try {
        const { publicKeyPem, privateKey } = await generateKeyPair();
        const res = await fetch(api.userApiKeys.store(), {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                publicKey: publicKeyPem,
                type: 'mcp',
                name: newKeyName.value || 'api-key',
                scopes: newKeyScopes.value.length ? newKeyScopes.value : null,
            }),
        });
        const data = await res.json();

        if (!res.ok) {
            throw new Error(data.message || t('profile.apikey_create_failed'));
        }

        const encrypted = Uint8Array.from(atob(data.api_key), (c) =>
            c.charCodeAt(0),
        );
        const decrypted = await window.crypto.subtle.decrypt(
            { name: 'RSA-OAEP' },
            privateKey,
            encrypted,
        );
        newApiKey.value = new TextDecoder().decode(decrypted);
        newApiKeyId.value = data.id;
        apiKeys.value.unshift({
            id: data.id,
            name: newKeyName.value || 'api-key',
            scopes: newKeyScopes.value.length ? [...newKeyScopes.value] : null,
            revoked_at: null,
            created_at: new Date().toISOString(),
        });
    } catch (e) {
        apiKeyError.value =
            (e instanceof Error ? e.message : null) ||
            t('profile.apikey_create_failed');
    } finally {
        newApiKeyLoading.value = false;
    }
}

async function copyKey(key: string) {
    await navigator.clipboard.writeText(key);
    copied.value = true;
    setTimeout(() => {
        copied.value = false;
    }, 1000);
}

async function setRevoked(id: number, revoked: boolean) {
    const res = await fetch(api.userApiKeys.update(id), {
        method: 'PATCH',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ revoked }),
    });

    if (res.ok) {
        const key = apiKeys.value.find((k) => k.id === id);

        if (key) {
            key.revoked_at = revoked ? new Date().toISOString() : null;
        }
    }
}

async function deleteApiKey(id: number) {
    const res = await fetch(api.userApiKeys.destroy(id), {
        method: 'DELETE',
        credentials: 'include',
    });

    if (res.ok) {
        apiKeys.value = apiKeys.value.filter((k) => k.id !== id);
    }
}

onMounted(fetchApiKeys);

// ── 改名 ──────────────────────────────────────────────────────
const nameForm = reactive({ name: '' });
watch(
    user,
    (u) => {
        if (u && !nameForm.name) {
            nameForm.name = u.name;
        }
    },
    { immediate: true },
);
const nameSubmitting = ref(false);
const nameError = ref('');
const nameSuccess = ref('');

async function submitName() {
    nameError.value = '';
    nameSuccess.value = '';
    nameSubmitting.value = true;

    try {
        const res = await fetch(api.auth.updateName(), {
            method: 'PATCH',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ name: nameForm.name }),
        });
        const data = await res.json();

        if (!res.ok) {
            nameError.value =
                data?.errors?.name?.[0] ??
                data?.message ??
                t('profile.name_update_failed');
        } else {
            nameSuccess.value = data.message ?? t('profile.name_updated');
            router.reload({ only: ['auth'] });
        }
    } catch {
        nameError.value = t('profile.error_request_failed');
    } finally {
        nameSubmitting.value = false;
    }
}

// ── 改密碼 ─────────────────────────────────────────────────────
const show = reactive({ current: false, password: false, confirm: false });
const form = reactive({
    current_password: '',
    password: '',
    password_confirmation: '',
});
const isSubmitting = ref(false);
const generalError = ref('');
const successMessage = ref('');
const fieldErrors = ref<Record<string, string[]>>({});

async function submit() {
    generalError.value = '';
    successMessage.value = '';
    fieldErrors.value = {};
    isSubmitting.value = true;

    try {
        const res = await changePasswordWithApi({
            current_password: await encryptPassword(form.current_password),
            password: await encryptPassword(form.password),
            password_confirmation: await encryptPassword(
                form.password_confirmation,
            ),
        });
        successMessage.value = res?.message ?? t('profile.password_updated');
        form.current_password = '';
        form.password = '';
        form.password_confirmation = '';
    } catch (error) {
        if (error instanceof AuthApiError) {
            generalError.value = error.message;
            fieldErrors.value = error.fieldErrors;
        } else {
            generalError.value = t('profile.error_update_failed');
        }
    } finally {
        isSubmitting.value = false;
    }
}
</script>

<template>
    <!-- User info card -->
    <div class="mb-6 flex items-center gap-4">
        <img
            :src="String(user?.avatar ?? routes.assets.avatarDefault('user'))"
            alt="avatar"
            class="h-12 w-12 rounded-full object-cover ring-2 ring-[var(--binary-primary)]/20"
        />
        <div class="min-w-0">
            <p
                class="font-mono text-sm font-semibold text-[var(--binary-text)]"
            >
                {{ user?.name }}
            </p>
            <p
                class="truncate font-mono text-xs text-[var(--binary-text-muted)]"
            >
                {{ user?.email }}
            </p>
            <span
                v-if="user?.email_verified_at"
                class="binary-label mt-0.5 inline-block text-[9px] font-bold text-[var(--binary-primary)] uppercase"
                >VERIFIED</span
            >
            <span
                v-else
                class="binary-label mt-0.5 inline-block text-[9px] font-bold text-[var(--binary-tertiary)] uppercase"
                >UNVERIFIED</span
            >
        </div>
    </div>

    <!-- Tab bar -->
    <div class="flex border-b border-[var(--binary-outline-variant)]">
        <button
            v-for="tab in ['name', 'password', 'apikey'] as const"
            :key="tab"
            type="button"
            class="binary-label px-4 py-2.5 text-[10px] font-bold tracking-widest uppercase transition-colors"
            :class="
                activeTab === tab
                    ? '-mb-px border-b-2 border-[var(--binary-primary)] text-[var(--binary-primary)]'
                    : 'text-[var(--binary-outline)] hover:text-[var(--binary-text)]'
            "
            @click="activeTab = tab"
        >
            {{
                tab === 'name'
                    ? t('profile.tab_rename')
                    : tab === 'password'
                      ? t('profile.tab_password')
                      : 'API-KEY'
            }}
        </button>
    </div>

    <div class="py-5">
        <!-- 改名 -->
        <form
            v-if="activeTab === 'name'"
            class="space-y-4"
            @submit.prevent="submitName"
        >
            <div class="space-y-1.5">
                <label
                    class="binary-label block text-[11px] font-bold text-[var(--binary-outline)] uppercase"
                    for="pp-name"
                >
                    {{ t('profile.label_display_name') }}
                </label>
                <input
                    id="pp-name"
                    v-model="nameForm.name"
                    class="binary-input"
                    type="text"
                    :placeholder="t('profile.placeholder_name')"
                    maxlength="255"
                />
            </div>
            <p
                v-if="nameError"
                class="border border-red-400/20 bg-red-950/20 px-4 py-3 text-sm text-red-200"
            >
                {{ nameError }}
            </p>
            <p
                v-if="nameSuccess"
                class="border border-[var(--binary-primary-container)]/20 bg-[var(--binary-primary-container)]/10 px-4 py-3 text-sm text-[var(--binary-primary)]"
            >
                {{ nameSuccess }}
            </p>
            <button
                class="binary-button"
                :disabled="nameSubmitting"
                type="submit"
            >
                {{
                    nameSubmitting
                        ? t('profile.submitting')
                        : t('profile.submit_rename')
                }}
                <span aria-hidden="true">-></span>
            </button>
        </form>

        <!-- 改密碼 -->
        <form
            v-if="activeTab === 'password'"
            class="space-y-4"
            @submit.prevent="submit"
        >
            <div class="space-y-1.5">
                <label
                    class="binary-label block text-[11px] font-bold text-[var(--binary-outline)] uppercase"
                    for="pp-current"
                    >{{ t('profile.label_current_password') }}</label
                >
                <div class="relative">
                    <input
                        id="pp-current"
                        v-model="form.current_password"
                        class="binary-input pr-10"
                        :type="show.current ? 'text' : 'password'"
                        placeholder="••••••••"
                        autocomplete="current-password"
                    />
                    <button
                        type="button"
                        class="absolute inset-y-0 right-3 flex items-center text-[var(--binary-outline)] transition-colors hover:text-[var(--binary-text)]"
                        @click="show.current = !show.current"
                    >
                        <svg
                            v-if="show.current"
                            class="h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"
                            />
                        </svg>
                        <svg
                            v-else
                            class="h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                            />
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                            />
                        </svg>
                    </button>
                </div>
                <p
                    v-if="fieldErrors.current_password?.length"
                    class="text-xs text-red-300"
                >
                    {{ fieldErrors.current_password[0] }}
                </p>
            </div>
            <div class="space-y-1.5">
                <label
                    class="binary-label block text-[11px] font-bold text-[var(--binary-outline)] uppercase"
                    for="pp-password"
                    >{{ t('profile.label_new_password') }}</label
                >
                <div class="relative">
                    <input
                        id="pp-password"
                        v-model="form.password"
                        class="binary-input pr-10"
                        :type="show.password ? 'text' : 'password'"
                        placeholder="••••••••"
                        autocomplete="new-password"
                    />
                    <button
                        type="button"
                        class="absolute inset-y-0 right-3 flex items-center text-[var(--binary-outline)] transition-colors hover:text-[var(--binary-text)]"
                        @click="show.password = !show.password"
                    >
                        <svg
                            v-if="show.password"
                            class="h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"
                            />
                        </svg>
                        <svg
                            v-else
                            class="h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                            />
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                            />
                        </svg>
                    </button>
                </div>
                <p
                    v-if="fieldErrors.password?.length"
                    class="text-xs text-red-300"
                >
                    {{ fieldErrors.password[0] }}
                </p>
            </div>
            <div class="space-y-1.5">
                <label
                    class="binary-label block text-[11px] font-bold text-[var(--binary-outline)] uppercase"
                    for="pp-confirm"
                    >{{ t('profile.label_confirm_password') }}</label
                >
                <div class="relative">
                    <input
                        id="pp-confirm"
                        v-model="form.password_confirmation"
                        class="binary-input pr-10"
                        :type="show.confirm ? 'text' : 'password'"
                        placeholder="••••••••"
                        autocomplete="new-password"
                    />
                    <button
                        type="button"
                        class="absolute inset-y-0 right-3 flex items-center text-[var(--binary-outline)] transition-colors hover:text-[var(--binary-text)]"
                        @click="show.confirm = !show.confirm"
                    >
                        <svg
                            v-if="show.confirm"
                            class="h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"
                            />
                        </svg>
                        <svg
                            v-else
                            class="h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                            />
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                            />
                        </svg>
                    </button>
                </div>
            </div>
            <p
                v-if="generalError"
                class="border border-red-400/20 bg-red-950/20 px-4 py-3 text-sm text-red-200"
            >
                {{ generalError }}
            </p>
            <p
                v-if="successMessage"
                class="border border-[var(--binary-primary-container)]/20 bg-[var(--binary-primary-container)]/10 px-4 py-3 text-sm text-[var(--binary-primary)]"
            >
                {{ successMessage }}
            </p>
            <button
                class="binary-button"
                :disabled="isSubmitting"
                type="submit"
            >
                {{
                    isSubmitting
                        ? t('profile.submitting')
                        : t('profile.submit_password')
                }}
                <span aria-hidden="true">-></span>
            </button>
        </form>

        <!-- API-KEY -->
        <div v-if="activeTab === 'apikey'">
            <div class="mb-4 flex items-center justify-between">
                <span class="text-sm font-bold text-[var(--binary-text)]">{{
                    t('profile.apikey_title')
                }}</span>
                <button
                    class="binary-button w-fit px-4 py-1.5 text-xs"
                    @click="openCreateModal"
                >
                    {{ t('profile.apikey_generate') }}
                </button>
            </div>
            <div v-if="apiKeyLoading" class="text-xs opacity-50">
                {{ t('common.loading') }}
            </div>
            <div v-else class="space-y-2">
                <div
                    v-for="key in apiKeys"
                    :key="key.id"
                    class="flex flex-wrap items-center gap-2 rounded-lg border px-3 py-2"
                    :class="
                        key.revoked_at
                            ? 'border-red-500/20'
                            : 'border-[var(--binary-outline-variant)]'
                    "
                >
                    <span
                        class="flex-1 truncate text-sm font-medium text-[var(--binary-text)]"
                        :class="key.revoked_at ? 'opacity-40' : ''"
                        >{{ key.name }}</span
                    >
                    <template v-if="key.scopes?.length">
                        <span
                            v-for="scope in key.scopes"
                            :key="scope"
                            class="shrink-0 rounded border border-purple-500/20 bg-purple-500/10 px-1.5 py-0.5 font-mono text-xs text-purple-400"
                            >{{ scope }}</span
                        >
                    </template>
                    <div class="flex shrink-0 gap-1">
                        <button
                            v-if="key.revoked_at"
                            class="rounded border border-green-500/30 px-2 py-0.5 text-xs text-green-400 hover:bg-green-500/10"
                            @click="setRevoked(key.id, false)"
                        >
                            {{ t('profile.apikey_restore') }}
                        </button>
                        <button
                            v-else
                            class="rounded border border-yellow-500/30 px-2 py-0.5 text-xs text-yellow-400 hover:bg-yellow-500/10"
                            @click="setRevoked(key.id, true)"
                        >
                            {{ t('profile.apikey_revoke') }}
                        </button>
                        <button
                            class="rounded border border-red-500/30 px-2 py-0.5 text-xs text-red-400 hover:bg-red-500/10"
                            @click="deleteApiKey(key.id)"
                        >
                            {{ t('profile.apikey_delete') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- API Key Modal -->
    <Teleport to="body">
        <div
            v-if="showCreateModal"
            class="fixed inset-0 z-[80] flex items-center justify-center bg-black/60 backdrop-blur-sm"
            @click.self="closeCreateModal"
        >
            <div
                class="mx-4 w-full max-w-md rounded-xl border border-[var(--binary-outline-variant)] bg-[var(--binary-surface)] p-6 shadow-2xl"
            >
                <div class="mb-5 text-base font-bold">
                    {{ t('profile.apikey_generate') }}
                </div>
                <div class="mb-4">
                    <label
                        class="mb-1.5 block text-xs text-[var(--binary-outline)]"
                        >名稱</label
                    >
                    <input
                        v-model="newKeyName"
                        class="binary-input w-full text-sm"
                        type="text"
                        placeholder="api-key"
                        maxlength="64"
                    />
                </div>
                <div class="mb-5">
                    <label
                        class="mb-2 block text-xs text-[var(--binary-outline)]"
                        >Scope <span class="text-red-400">*</span></label
                    >
                    <div class="flex flex-wrap gap-2">
                        <template
                            v-for="s in apiKeyScopeOptions"
                            :key="s.value"
                        >
                            <button
                                v-if="!s.adminOnly || isAdmin"
                                type="button"
                                class="rounded border px-3 py-1.5 font-mono text-xs transition-colors"
                                :class="
                                    newKeyScopes.includes(s.value)
                                        ? 'border-[var(--binary-primary)] bg-[var(--binary-primary)]/10 text-[var(--binary-primary)]'
                                        : 'border-[var(--binary-outline-variant)] text-[var(--binary-outline)] hover:border-[var(--binary-outline)]'
                                "
                                @click="
                                    newKeyScopes.includes(s.value)
                                        ? newKeyScopes.splice(
                                              newKeyScopes.indexOf(s.value),
                                              1,
                                          )
                                        : newKeyScopes.push(s.value)
                                "
                            >
                                {{ s.value }}
                            </button>
                        </template>
                    </div>
                </div>
                <div
                    v-if="newApiKey"
                    class="mb-4 rounded-lg border border-green-500/30 bg-green-500/5 p-3"
                >
                    <div
                        class="mb-2 text-xs font-bold tracking-wider text-green-400"
                    >
                        {{ t('profile.apikey_once_hint') }}
                    </div>
                    <div class="flex items-center gap-2">
                        <code
                            class="flex-1 font-mono text-xs break-all text-green-300 select-all"
                            >{{ newApiKey }}</code
                        >
                        <button
                            class="shrink-0 rounded border px-3 py-1 text-xs transition-colors"
                            :class="
                                copied
                                    ? 'border-green-500 text-green-400'
                                    : 'border-[var(--binary-outline)] text-[var(--binary-text)] hover:border-[var(--binary-primary)]'
                            "
                            @click="copyKey(newApiKey)"
                        >
                            {{
                                copied
                                    ? t('profile.apikey_copied')
                                    : t('profile.apikey_copy')
                            }}
                        </button>
                    </div>
                </div>
                <div v-if="apiKeyError" class="mb-3 text-xs text-red-400">
                    {{ apiKeyError }}
                </div>
                <div class="flex gap-2">
                    <button
                        class="flex-1 rounded border border-[var(--binary-outline-variant)] py-2 text-xs text-[var(--binary-outline)] hover:border-[var(--binary-outline)]"
                        @click="closeCreateModal"
                    >
                        {{ newApiKey ? '完成' : '取消' }}
                    </button>
                    <button
                        v-if="!newApiKey"
                        class="binary-button flex-1 py-2 text-xs"
                        :disabled="newApiKeyLoading"
                        @click="createApiKey"
                    >
                        {{
                            newApiKeyLoading
                                ? t('profile.apikey_generating')
                                : t('profile.apikey_generate')
                        }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

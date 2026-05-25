<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '../layouts/AppLayout.vue';
import { useAuth } from '../composables/useAuth';
import { AuthApiError, changePasswordWithApi } from '../lib/auth-api';
import { encryptPassword } from '../lib/crypto';
import { api, routes } from '../lib/routes';

const { t } = useI18n();

const { user } = useAuth();


const activeTab = ref<'password' | 'name' | 'apikey'>('password');

// ── API-KEY 管理 ───────────────────────────────
import { onMounted } from 'vue';

interface ApiKey {
    id: number;
    name: string;
    type: string;
    revoked_at: string | null;
    created_at: string;
}

const apiKeys = ref<ApiKey[]>([]);
const apiKeyLoading = ref(false);
const apiKeyError = ref('');
const newApiKey = ref('');
const newApiKeyId = ref<number|null>(null);
const newApiKeyLoading = ref(false);
const newKeyType = ref('mcp');
const newKeyName = ref('api-key');
const copied = ref(false);

const KEY_TYPES = ['mcp'];

// 取得 API 金鑰清單
async function fetchApiKeys() {
    apiKeyLoading.value = true;
    apiKeyError.value = '';
    try {
        const res = await fetch(api.userApiKeys.index(), { credentials: 'include' });
        apiKeys.value = await res.json();
    } catch (e) {
        apiKeyError.value = t('profile.apikey_fetch_failed');
    } finally {
        apiKeyLoading.value = false;
    }
}

function bufferToPem(buffer: ArrayBuffer, label: string): string {
    const bytes = new Uint8Array(buffer)
    let str = ''
    for (let i = 0; i < bytes.length; i++) str += String.fromCharCode(bytes[i])
    const b64 = btoa(str).replace(/(.{64})/g, '$1\n')
    return `-----BEGIN ${label}-----\n${b64}\n-----END ${label}-----`
}

// 前端產生 RSA 金鑰對，私鑰只存在記憶體（不持久化）
async function generateKeyPair(): Promise<{ publicKeyPem: string; privateKey: CryptoKey }> {
    const keyPair = await window.crypto.subtle.generateKey(
        { name: 'RSA-OAEP', modulusLength: 2048, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-1' },
        false,
        ['encrypt', 'decrypt']
    )
    const spki = await window.crypto.subtle.exportKey('spki', keyPair.publicKey)
    return { publicKeyPem: bufferToPem(spki, 'PUBLIC KEY'), privateKey: keyPair.privateKey }
}

// 新增 API 金鑰
async function createApiKey() {
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
            body: JSON.stringify({ publicKey: publicKeyPem, type: newKeyType.value, name: newKeyName.value || 'api-key' }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.message || t('profile.apikey_create_failed'));
        // 解密回傳的 api_key（私鑰只存在此 function 的 scope）
        const encrypted = Uint8Array.from(atob(data.api_key), c => c.charCodeAt(0));
        const decrypted = await window.crypto.subtle.decrypt({ name: 'RSA-OAEP' }, privateKey, encrypted);
        newApiKey.value = new TextDecoder().decode(decrypted);
        newApiKeyId.value = data.id;
        apiKeys.value.unshift({
            id: data.id,
            name: newKeyName.value || 'api-key',
            type: newKeyType.value,
            revoked_at: null,
            created_at: new Date().toISOString(),
        });
    } catch (e) {
        apiKeyError.value = (e instanceof Error ? e.message : null) || t('profile.apikey_create_failed');
    } finally {
        newApiKeyLoading.value = false;
    }
}

async function copyKey(key: string) {
    await navigator.clipboard.writeText(key);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 1000);
}

async function setRevoked(id: number, revoked: boolean) {
    const res = await fetch(api.userApiKeys.update(id), {
        method: 'PATCH',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ revoked }),
    });
    if (res.ok) {
        const key = apiKeys.value.find(k => k.id === id);
        if (key) key.revoked_at = revoked ? new Date().toISOString() : null;
    }
}

async function deleteApiKey(id: number) {
    const res = await fetch(api.userApiKeys.destroy(id), { method: 'DELETE', credentials: 'include' });
    if (res.ok) apiKeys.value = apiKeys.value.filter(k => k.id !== id);
}

onMounted(() => {
    fetchApiKeys();
});

// ── 改名 ────────────────────────────────────────────────
const nameForm = reactive({ name: '' });
watch(user, (u) => { if (u && !nameForm.name) nameForm.name = u.name; }, { immediate: true });
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
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ name: nameForm.name }),
        });
        const data = await res.json();
        if (!res.ok) {
            nameError.value = data?.errors?.name?.[0] ?? data?.message ?? t('profile.name_update_failed');
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
            password_confirmation: await encryptPassword(form.password_confirmation),
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
    <Head :title="t('profile.head_title')" />
    <AppLayout>
        <div class="mx-auto w-full max-w-screen-2xl px-6 pb-16 md:px-8" style="padding-top: 6rem;">

            <p class="binary-label mb-2 text-xs font-bold uppercase text-[var(--binary-primary)]">&gt; {{ t('profile.breadcrumb') }}</p>
            <h1 class="binary-display mb-12 text-4xl font-black uppercase tracking-tight text-[var(--binary-text)] md:text-6xl">{{ t('profile.title') }}</h1>

            <div class="grid gap-6 md:grid-cols-[220px_1fr]">

                <!-- 帳號資訊 -->
                <div class="rounded-2xl bg-[var(--binary-surface-low)] p-6" style="box-shadow: inset 4px 0 0 0 var(--binary-primary);">
                    <p class="binary-label mb-4 text-[10px] font-bold uppercase tracking-widest text-[var(--binary-outline)]">{{ t('profile.section_account') }}</p>
                    <div v-if="user" class="flex flex-col gap-4">
                        <img
                            :src="String(user.avatar || routes.assets.avatarDefault('user'))"
                            alt="avatar"
                            class="h-16 w-16 rounded-full object-cover"
                        >
                        <div>
                            <p class="binary-label text-[10px] uppercase text-[var(--binary-outline)]">{{ t('profile.field_name') }}</p>
                            <p class="mt-1 font-mono text-sm text-[var(--binary-text)]">{{ user.name }}</p>
                        </div>
                        <div>
                            <p class="binary-label text-[10px] uppercase text-[var(--binary-outline)]">{{ t('profile.field_email') }}</p>
                            <p class="mt-1 font-mono text-sm text-[var(--binary-text)]">{{ user.email }}</p>
                            <span
                                v-if="user.email_verified_at"
                                class="mt-1 inline-block binary-label text-[9px] uppercase text-[var(--binary-primary)]"
                            >{{ t('profile.verified') }}</span>
                        </div>
                    </div>
                </div>

                <!-- 修改密碼 -->
                <div class="rounded-2xl border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-container)] overflow-hidden">
                    <!-- Tabs -->
                    <div class="flex border-b border-[var(--binary-outline-variant)]">
                        <button
                            type="button"
                            class="binary-label px-6 py-3 text-[11px] font-bold uppercase tracking-widest transition-colors"
                            :class="activeTab === 'name'
                                ? 'border-b-2 border-[var(--binary-primary)] text-[var(--binary-primary)] -mb-px'
                                : 'text-[var(--binary-outline)] hover:text-[var(--binary-text)]'"
                            @click="activeTab = 'name'"
                        >{{ t('profile.tab_rename') }}</button>
                        <button
                            type="button"
                            class="binary-label px-6 py-3 text-[11px] font-bold uppercase tracking-widest transition-colors"
                            :class="activeTab === 'password'
                                ? 'border-b-2 border-[var(--binary-primary)] text-[var(--binary-primary)] -mb-px'
                                : 'text-[var(--binary-outline)] hover:text-[var(--binary-text)]'"
                            @click="activeTab = 'password'"
                        >{{ t('profile.tab_password') }}</button>
                        <button
                            type="button"
                            class="binary-label px-6 py-3 text-[11px] font-bold uppercase tracking-widest transition-colors"
                            :class="activeTab === 'apikey'
                                ? 'border-b-2 border-[var(--binary-primary)] text-[var(--binary-primary)] -mb-px'
                                : 'text-[var(--binary-outline)] hover:text-[var(--binary-text)]'"
                            @click="activeTab = 'apikey'"
                        >API-KEY</button>
                    </div>

                    <div class="px-10 py-6">

                    <!-- 改名 tab -->
                    <form v-if="activeTab === 'name'" class="space-y-5 max-w-sm" @submit.prevent="submitName">
                        <div class="space-y-1.5">
                            <label class="binary-label block text-[11px] font-bold uppercase text-[var(--binary-outline)]" for="display_name">
                                {{ t('profile.label_display_name') }}
                            </label>
                            <input
                                id="display_name"
                                v-model="nameForm.name"
                                class="binary-input"
                                type="text"
                                :placeholder="t('profile.placeholder_name')"
                                maxlength="255"
                            >
                        </div>
                        <p v-if="nameError" class="border border-red-400/20 bg-red-950/20 px-4 py-3 text-sm text-red-200">{{ nameError }}</p>
                        <p v-if="nameSuccess" class="border border-[var(--binary-primary-container)]/20 bg-[var(--binary-primary-container)]/10 px-4 py-3 text-sm text-[var(--binary-primary)]">{{ nameSuccess }}</p>
                        <button class="binary-button" :disabled="nameSubmitting" type="submit">
                            {{ nameSubmitting ? t('profile.submitting') : t('profile.submit_rename') }}
                            <span aria-hidden="true">-></span>
                        </button>
                    </form>

                    <form v-if="activeTab === 'password'" class="space-y-5 max-w-sm" @submit.prevent="submit">
                        <div class="space-y-1.5">
                            <label class="binary-label block text-[11px] font-bold uppercase text-[var(--binary-outline)]" for="current_password">
                                {{ t('profile.label_current_password') }}
                            </label>
                            <div class="relative">
                                <input
                                    id="current_password"
                                    v-model="form.current_password"
                                    class="binary-input pr-10"
                                    :type="show.current ? 'text' : 'password'"
                                    placeholder="••••••••"
                                    autocomplete="current-password"
                                    @input="fieldErrors.current_password = []"
                                >
                                <button type="button" class="absolute inset-y-0 right-3 flex items-center text-[var(--binary-outline)] hover:text-[var(--binary-text)] transition-colors" @click="show.current = !show.current">
                                    <svg v-if="show.current" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" /></svg>
                                    <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                </button>
                            </div>
                            <p v-if="fieldErrors.current_password?.length" class="text-xs text-red-300">
                                {{ fieldErrors.current_password[0] }}
                            </p>
                        </div>

                        <div class="space-y-1.5">
                            <label class="binary-label block text-[11px] font-bold uppercase text-[var(--binary-outline)]" for="password">
                                {{ t('profile.label_new_password') }}
                            </label>
                            <div class="relative">
                                <input
                                    id="password"
                                    v-model="form.password"
                                    class="binary-input pr-10"
                                    :type="show.password ? 'text' : 'password'"
                                    placeholder="••••••••"
                                    autocomplete="new-password"
                                    @input="fieldErrors.password = []"
                                >
                                <button type="button" class="absolute inset-y-0 right-3 flex items-center text-[var(--binary-outline)] hover:text-[var(--binary-text)] transition-colors" @click="show.password = !show.password">
                                    <svg v-if="show.password" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" /></svg>
                                    <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                </button>
                            </div>
                            <p v-if="fieldErrors.password?.length" class="text-xs text-red-300">
                                {{ fieldErrors.password[0] }}
                            </p>
                        </div>

                        <div class="space-y-1.5">
                            <label class="binary-label block text-[11px] font-bold uppercase text-[var(--binary-outline)]" for="password_confirmation">
                                {{ t('profile.label_confirm_password') }}
                            </label>
                            <div class="relative">
                                <input
                                    id="password_confirmation"
                                    v-model="form.password_confirmation"
                                    class="binary-input pr-10"
                                    :type="show.confirm ? 'text' : 'password'"
                                    placeholder="••••••••"
                                    autocomplete="new-password"
                                >
                                <button type="button" class="absolute inset-y-0 right-3 flex items-center text-[var(--binary-outline)] hover:text-[var(--binary-text)] transition-colors" @click="show.confirm = !show.confirm">
                                    <svg v-if="show.confirm" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" /></svg>
                                    <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                </button>
                            </div>
                        </div>

                        <p v-if="generalError" class="border border-red-400/20 bg-red-950/20 px-4 py-3 text-sm text-red-200">
                            {{ generalError }}
                        </p>

                        <p v-if="successMessage" class="border border-[var(--binary-primary-container)]/20 bg-[var(--binary-primary-container)]/10 px-4 py-3 text-sm text-[var(--binary-primary)]">
                            {{ successMessage }}
                        </p>

                        <button class="binary-button" :disabled="isSubmitting" type="submit">
                            {{ isSubmitting ? t('profile.submitting') : t('profile.submit_password') }}
                            <span aria-hidden="true">-></span>
                        </button>
                    </form>

                    <!-- API-KEY tab -->
                    <div v-if="activeTab === 'apikey'">
                        <div class="text-lg font-bold mb-4">{{ t('profile.apikey_title') }}</div>

                        <!-- 產生新 key -->
                        <div class="flex items-center gap-2 mb-4">
                            <input
                                v-model="newKeyName"
                                class="binary-input flex-1 text-xs"
                                type="text"
                                placeholder="api-key"
                                maxlength="64"
                            />
                            <select v-model="newKeyType" class="binary-input w-28 text-xs shrink-0">
                                <option v-for="tp in KEY_TYPES" :key="tp" :value="tp">{{ tp }}</option>
                            </select>
                            <button class="binary-button w-28 shrink-0" :disabled="newApiKeyLoading" @click="createApiKey">
                                {{ newApiKeyLoading ? t('profile.apikey_generating') : t('profile.apikey_generate') }}
                            </button>
                        </div>

                        <!-- 新產生的 key（一次性顯示）-->
                        <div v-if="newApiKey" class="mb-5 p-3 rounded-lg border border-green-500/30 bg-green-500/5">
                            <div class="mb-2 text-xs text-green-400 font-bold tracking-wider">{{ t('profile.apikey_once_hint') }}</div>
                            <div class="flex items-center gap-2">
                                <code class="flex-1 text-xs font-mono break-all text-green-300 select-all">{{ newApiKey }}</code>
                                <button
                                    class="shrink-0 px-3 py-1 rounded border text-xs transition-colors"
                                    :class="copied ? 'border-green-500 text-green-400' : 'border-[var(--binary-outline)] text-[var(--binary-text)] hover:border-[var(--binary-primary)]'"
                                    @click="copyKey(newApiKey)"
                                >{{ copied ? t('profile.apikey_copied') : t('profile.apikey_copy') }}</button>
                            </div>
                        </div>

                        <div v-if="apiKeyError" class="text-red-400 text-xs mb-3">{{ apiKeyError }}</div>

                        <!-- 金鑰清單 -->
                        <div v-if="apiKeyLoading" class="text-xs opacity-50">{{ t('common.loading') }}</div>
                        <div v-else class="space-y-2">
                            <div
                                v-for="key in apiKeys"
                                :key="key.id"
                                class="flex items-center gap-3 px-3 py-2 rounded-lg border"
                                :class="key.revoked_at ? 'border-red-500/20' : 'border-[var(--binary-outline-variant)]'"
                            >
                                <span class="font-mono text-xs px-2 py-0.5 rounded bg-[var(--binary-surface-container)] text-[var(--binary-primary)] shrink-0 transition-opacity" :class="key.revoked_at ? 'opacity-40' : ''">{{ key.type }}</span>
                                <span class="text-sm font-medium text-[var(--binary-text)] flex-1 truncate transition-opacity" :class="key.revoked_at ? 'opacity-40' : ''">{{ key.name }}</span>
                                <span class="text-xs text-[var(--binary-outline)] shrink-0 transition-opacity" :class="key.revoked_at ? 'opacity-40' : ''">
                                    {{ t('profile.apikey_created_label') }} {{ new Date(key.created_at).toLocaleDateString() }}
                                    <template v-if="key.revoked_at"> · {{ t('profile.apikey_revoked_label') }} {{ new Date(key.revoked_at).toLocaleDateString() }}</template>
                                </span>
                                <button
                                    v-if="key.revoked_at"
                                    class="text-xs px-2 py-0.5 rounded border border-green-500/30 text-green-400 hover:bg-green-500/10 transition-colors"
                                    @click="setRevoked(key.id, false)"
                                >{{ t('profile.apikey_restore') }}</button>
                                <button
                                    v-else
                                    class="text-xs px-2 py-0.5 rounded border border-yellow-500/30 text-yellow-400 hover:bg-yellow-500/10 transition-colors"
                                    @click="setRevoked(key.id, true)"
                                >{{ t('profile.apikey_revoke') }}</button>
                                <button
                                    class="text-xs px-2 py-0.5 rounded border border-red-500/30 text-red-400 hover:bg-red-500/10 transition-colors"
                                    @click="deleteApiKey(key.id)"
                                >{{ t('profile.apikey_delete') }}</button>
                            </div>
                        </div>
                    </div>
                    </div><!-- /tab content -->
                </div>

            </div>
        </div>
    </AppLayout>
</template>

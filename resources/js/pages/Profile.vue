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

const activeTab = ref<'password' | 'name'>('password');

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
            nameError.value = data?.errors?.name?.[0] ?? data?.message ?? '更新失敗';
        } else {
            nameSuccess.value = data.message ?? '名稱已更新';
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
        successMessage.value = res?.message ?? '密碼已成功更新';
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
                    </div><!-- /tab content -->
                </div>

            </div>
        </div>
    </AppLayout>
</template>

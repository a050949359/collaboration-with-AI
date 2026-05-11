<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

import AuthShell from '../../layouts/AuthShell.vue';
import { AuthApiError, registerWithApi } from '../../lib/auth-api';
import { encryptPassword } from '../../lib/crypto';

import Turnstile from '../../components/common/Turnstile.vue';

const turnstileEnabled = import.meta.env.VITE_TURNSTILE_ENABLED !== 'false';

const form = reactive({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    terms: false,
    cf_turnstile_response: null,
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
        const [encPwd, encPwdConfirm] = await Promise.all([
            encryptPassword(form.password),
            encryptPassword(form.password_confirmation),
        ]);
        const response = await registerWithApi({
            name: form.name,
            email: form.email,
            password: encPwd,
            password_confirmation: encPwdConfirm,
            terms: form.terms,
            cf_turnstile_response: form.cf_turnstile_response ?? undefined,
        });

        successMessage.value = response.message || '註冊成功，前端已收到 API 回應。';
        window.location.href = response.redirect || '/';

        return;
    } catch (error) {
        if (error instanceof AuthApiError) {
            generalError.value = error.message;
            fieldErrors.value = error.fieldErrors;
        } else if (error instanceof Error) {
            generalError.value = error.message;
        } else {
            generalError.value = '註冊失敗，請稍後再試。';
        }
    } finally {
        isSubmitting.value = false;
    }
}
</script>

<template>
    <Head title="Register" />

    <AuthShell
        eyebrow="> 用戶註冊"
        title="建立你的帳號"
        summary="填寫基本資料後即可建立帳號，完成後會直接進入首頁開始使用。"
        mode="register"
    >
        <form class="space-y-8" @submit.prevent="submit">
            <div class="space-y-2">
                <label class="binary-label block text-[11px] font-bold uppercase text-[var(--binary-outline)]" for="name">
                    全名 / name
                </label>
                <input
                    id="name"
                    v-model="form.name"
                    class="binary-input"
                    name="name"
                    placeholder="輸入您的名稱..."
                    type="text"
                >
                <p v-if="fieldErrors.name?.length" class="text-xs text-red-300">
                    {{ fieldErrors.name[0] }}
                </p>
            </div>

            <div class="space-y-2">
                <label class="binary-label block text-[11px] font-bold uppercase text-[var(--binary-outline)]" for="email">
                    電子郵件 / email
                </label>
                <input
                    id="email"
                    v-model="form.email"
                    class="binary-input"
                    name="email"
                    placeholder="user@terminal.sys"
                    type="email"
                    autocomplete="username"
                >
                <p v-if="fieldErrors.email?.length" class="text-xs text-red-300">
                    {{ fieldErrors.email[0] }}
                </p>
            </div>

            <div class="space-y-2">
                <label class="binary-label block text-[11px] font-bold uppercase text-[var(--binary-outline)]" for="password">
                    密碼 / password
                </label>
                <input
                    id="password"
                    v-model="form.password"
                    class="binary-input"
                    name="password"
                    placeholder="••••••••"
                    type="password"
                    autocomplete="new-password"
                >
                <p v-if="fieldErrors.password?.length" class="text-xs text-red-300">
                    {{ fieldErrors.password[0] }}
                </p>
            </div>

            <div class="space-y-2">
                <label class="binary-label block text-[11px] font-bold uppercase text-[var(--binary-outline)]" for="password_confirmation">
                    確認密碼 / password_confirmation
                </label>
                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    class="binary-input"
                    name="password_confirmation"
                    placeholder="••••••••"
                    type="password"
                    autocomplete="new-password"
                >
                <p v-if="fieldErrors.password_confirmation?.length" class="text-xs text-red-300">
                    {{ fieldErrors.password_confirmation[0] }}
                </p>
            </div>

            <label class="flex items-start gap-3 text-xs leading-6 text-[var(--binary-text-muted)]">
                <input
                    v-model="form.terms"
                    class="mt-1 h-4 w-4 border-0 bg-[var(--binary-surface-high)] text-[var(--binary-primary-container)] focus:ring-0"
                    name="terms"
                    type="checkbox"
                >
                <span>
                    我同意遵守服務條款與隱私權政策。系統將自動記錄這次註冊請求。
                </span>
            </label>

            <p v-if="fieldErrors.terms?.length" class="text-xs text-red-300">
                {{ fieldErrors.terms[0] }}
            </p>

            <p v-if="generalError" class="border border-red-400/20 bg-red-950/20 px-4 py-3 text-sm text-red-200">
                {{ generalError }}
            </p>

            <p v-if="successMessage" class="border border-[var(--binary-primary-container)]/20 bg-[var(--binary-primary-container)]/10 px-4 py-3 text-sm text-[var(--binary-primary)]">
                {{ successMessage }}
            </p>

            <div class="pt-4">
                <button class="binary-button" :disabled="isSubmitting" type="submit">
                    {{ isSubmitting ? '建立中...' : '執行註冊' }}
                    <span aria-hidden="true">-></span>
                </button>
            </div>

            <div v-if="turnstileEnabled" class="mt-4">
                <Turnstile v-model="form.cf_turnstile_response" />
                <div v-if="fieldErrors.cf_turnstile_response?.length" class="text-red-500 text-sm mt-1">
                    {{ fieldErrors.cf_turnstile_response[0] }}
                </div>
            </div>
        </form>

        <div class="mt-8 flex items-center justify-between gap-4">
            <Link class="binary-label text-[10px] uppercase text-[var(--binary-outline)] transition hover:text-[var(--binary-primary)]" href="/login">
                <span aria-hidden="true">&lt;-</span>
                返回登入界面
            </Link>

            <div class="flex flex-wrap gap-3">
                <button class="binary-ghost-button" type="button">GitHub</button>
                <button class="binary-ghost-button" type="button">Google</button>
            </div>
        </div>
    </AuthShell>
</template>
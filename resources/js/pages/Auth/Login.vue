<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

import AuthShell from '../../layouts/AuthShell.vue';
import { AuthApiError, getAuthApiConfig, loginWithApi } from '../../lib/auth-api';

const form = reactive({
    email: '',
    password: '',
    remember: false,
});

const isSubmitting = ref(false);
const generalError = ref('');
const successMessage = ref('');
const fieldErrors = ref<Record<string, string[]>>({});
const apiHint = computed(() => getAuthApiConfig().loginEndpoint);

async function submit() {
    generalError.value = '';
    successMessage.value = '';
    fieldErrors.value = {};
    isSubmitting.value = true;

    try {
        const response = await loginWithApi({
            email: form.email,
            password: form.password,
            remember: form.remember,
        });

        successMessage.value = response.message || '登入成功，前端已收到 API 回應。';
        window.location.href = response.redirect || '/';

        return;
    } catch (error) {
        if (error instanceof AuthApiError) {
            generalError.value = error.message;
            fieldErrors.value = error.fieldErrors;
        } else {
            generalError.value = '登入失敗，請稍後再試。';
        }
    } finally {
        isSubmitting.value = false;
    }
}
</script>

<template>
    <Head title="Login" />

    <AuthShell
        eyebrow="> 帳號登入"
        title="登入你的帳號"
        summary="輸入帳號與密碼後即可進入系統，繼續你的操作流程。"
        mode="login"
    >
        <form class="space-y-8" @submit.prevent="submit">
            <div class="space-y-2">
                <label class="binary-label block text-[11px] font-bold uppercase text-[var(--binary-outline)]" for="email">
                    帳號識別 / email
                </label>
                <input
                    id="email"
                    v-model="form.email"
                    class="binary-input"
                    name="email"
                    placeholder="root@terminal.dev"
                    type="email"
                >
                <p v-if="fieldErrors.email?.length" class="text-xs text-red-300">
                    {{ fieldErrors.email[0] }}
                </p>
            </div>

            <div class="space-y-2">
                <div class="flex items-center justify-between gap-4">
                    <label class="binary-label block text-[11px] font-bold uppercase text-[var(--binary-outline)]" for="password">
                        存取金鑰 / access_key
                    </label>
                    <a class="binary-label text-[10px] uppercase text-[var(--binary-outline)] transition hover:text-[var(--binary-primary)]" href="#">
                        忘記密碼
                    </a>
                </div>
                <input
                    id="password"
                    v-model="form.password"
                    class="binary-input"
                    name="password"
                    placeholder="••••••••"
                    type="password"
                >
                <p v-if="fieldErrors.password?.length" class="text-xs text-red-300">
                    {{ fieldErrors.password[0] }}
                </p>
            </div>

            <label class="flex items-center gap-3 text-xs text-[var(--binary-text-muted)]">
                <input
                    v-model="form.remember"
                    class="h-4 w-4 border-0 bg-[var(--binary-surface-high)] text-[var(--binary-primary-container)] focus:ring-0"
                    name="remember"
                    type="checkbox"
                >
                <span>保持登入狀態</span>
            </label>

            <p v-if="generalError" class="border border-red-400/20 bg-red-950/20 px-4 py-3 text-sm text-red-200">
                {{ generalError }}
            </p>

            <p v-if="successMessage" class="border border-[var(--binary-primary-container)]/20 bg-[var(--binary-primary-container)]/10 px-4 py-3 text-sm text-[var(--binary-primary)]">
                {{ successMessage }}
            </p>

            <div class="pt-4">
                <button class="binary-button" :disabled="isSubmitting" type="submit">
                    {{ isSubmitting ? '連線中...' : '驗證並登入' }}
                    <span aria-hidden="true">-></span>
                </button>
            </div>
        </form>

        <div class="mt-8 border-t border-[rgba(59,75,55,0.18)] pt-8">
            <div class="flex items-center justify-between gap-4 text-[10px] uppercase tracking-[0.18em] text-[var(--binary-outline)]">
                <span>狀態: 待命</span>
                <span>版本: 2.0.4-lts</span>
            </div>

            <div class="mt-6 flex items-center gap-2 text-sm">
                <span class="text-[var(--binary-text-muted)]">沒有帳號？</span>
                <Link class="font-semibold text-[var(--binary-primary)] transition hover:underline" href="/register">
                    立即建立帳號
                </Link>
            </div>

            <div class="mt-6 flex flex-wrap gap-3">
                <button class="binary-ghost-button" type="button">GitHub</button>
                <button class="binary-ghost-button" type="button">Google</button>
            </div>
        </div>

        <div class="mt-10 flex items-start gap-4 text-[10px] uppercase text-[var(--binary-outline)] opacity-70">
            <span class="binary-cursor mt-0.5" />
            <p class="leading-6">
                提醒：登入後將依你的權限載入對應功能，若裝置為共用環境請勿勾選保持登入。
                <br>
                please keep your account credentials secure.
            </p>
        </div>

        <div class="mt-8 border-t border-[rgba(59,75,55,0.18)] pt-6">
            <p class="binary-label text-[10px] uppercase leading-6 text-[var(--binary-outline)]">
                api endpoint: {{ apiHint }}
                <br>
                expected payload: email, password, remember
            </p>
        </div>
    </AuthShell>
</template>
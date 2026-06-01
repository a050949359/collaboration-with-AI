<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

import { reactive, ref } from 'vue';

import Turnstile from '../../components/common/Turnstile.vue';
import AuthShell from '../../layouts/AuthShell.vue';
import { AuthApiError, loginWithApi } from '../../lib/auth-api';
import { encryptPassword } from '../../lib/crypto';
import { routes, api } from '../../lib/routes';

const turnstileEnabled = import.meta.env.VITE_TURNSTILE_ENABLED !== 'false';

const form = reactive({
    email: '',
    password: '',
    remember: false,
    cf_turnstile_response: null,
});

const showPassword = ref(false);
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
        const response = await loginWithApi({
            email: form.email,
            password: await encryptPassword(form.password),
            remember: form.remember,
            cf_turnstile_response: form.cf_turnstile_response ?? undefined,
        });

        successMessage.value =
            response.message || '登入成功，前端已收到 API 回應。';
        window.location.href = response.redirect || '/';

        return;
    } catch (error) {
        if (error instanceof AuthApiError) {
            generalError.value = error.message;
            fieldErrors.value = error.fieldErrors;
        } else if (error instanceof Error) {
            generalError.value = error.message;
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
                <label
                    class="binary-label block text-[11px] font-bold text-[var(--binary-outline)] uppercase"
                    for="email"
                >
                    電子郵件 / email
                </label>
                <input
                    id="email"
                    v-model="form.email"
                    class="binary-input"
                    name="email"
                    placeholder="root@terminal.dev"
                    type="email"
                    autocomplete="username"
                />

                <p
                    v-if="fieldErrors.email?.length"
                    class="text-xs text-red-300"
                >
                    {{ fieldErrors.email[0] }}
                </p>
            </div>

            <div class="space-y-2">
                <div class="flex items-center justify-between gap-4">
                    <label
                        class="binary-label block text-[11px] font-bold text-[var(--binary-outline)] uppercase"
                        for="password"
                    >
                        密碼 / password
                    </label>
                    <Link
                        class="binary-label text-[10px] text-[var(--binary-outline)] uppercase transition hover:text-[var(--binary-primary)]"
                        :href="routes.forgotPassword()"
                    >
                        忘記密碼
                    </Link>
                </div>
                <div class="relative">
                    <input
                        id="password"
                        v-model="form.password"
                        class="binary-input pr-10"
                        name="password"
                        placeholder="••••••••"
                        :type="showPassword ? 'text' : 'password'"
                        autocomplete="current-password"
                    />
                    <button
                        type="button"
                        class="absolute inset-y-0 right-3 flex items-center text-[var(--binary-outline)] transition-colors hover:text-[var(--binary-text)]"
                        @click="showPassword = !showPassword"
                    >
                        <svg
                            v-if="showPassword"
                            xmlns="http://www.w3.org/2000/svg"
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
                            xmlns="http://www.w3.org/2000/svg"
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

            <label
                class="flex items-center gap-3 text-xs text-[var(--binary-text-muted)]"
            >
                <input
                    v-model="form.remember"
                    class="h-4 w-4 border-0 bg-[var(--binary-surface-high)] text-[var(--binary-primary-container)] focus:ring-0"
                    name="remember"
                    type="checkbox"
                />
                <span>保持登入狀態</span>
            </label>

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

            <div class="pt-4">
                <button
                    class="binary-button"
                    :disabled="isSubmitting"
                    type="submit"
                >
                    {{ isSubmitting ? '連線中...' : '驗證並登入' }}
                    <span aria-hidden="true">-></span>
                </button>
            </div>

            <div v-if="turnstileEnabled" class="mt-4">
                <Turnstile v-model="form.cf_turnstile_response" />
                <div
                    v-if="fieldErrors.cf_turnstile_response?.length"
                    class="mt-1 text-sm text-red-500"
                >
                    {{ fieldErrors.cf_turnstile_response[0] }}
                </div>
            </div>
        </form>

        <div class="mt-8 border-t border-[rgba(59,75,55,0.18)] pt-8">
            <div class="mt-6 flex items-center gap-2 text-sm">
                <span class="text-[var(--binary-text-muted)]">沒有帳號？</span>
                <Link
                    class="font-semibold text-[var(--binary-primary)] transition hover:underline"
                    :href="routes.register()"
                >
                    立即建立帳號
                </Link>
            </div>

            <div class="mt-6 flex flex-wrap gap-3">
                <button class="binary-ghost-button" type="button">
                    GitHub
                </button>
                <a class="binary-ghost-button" :href="api.auth.googleRedirect()"
                    >Google</a
                >
            </div>
        </div>

        <div
            class="mt-10 flex items-start gap-4 text-[10px] text-[var(--binary-outline)] uppercase opacity-70"
        >
            <span class="binary-cursor mt-0.5" />
            <p class="leading-6">
                提醒：登入後將依你的權限載入對應功能，若裝置為共用環境請勿勾選保持登入。
                <br />
                please keep your account credentials secure.
            </p>
        </div>
    </AuthShell>
</template>

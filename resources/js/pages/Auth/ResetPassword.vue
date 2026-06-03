<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import AuthShell from '../../layouts/AuthShell.vue';
import { AuthApiError, resetPasswordWithApi } from '../../lib/auth-api';
import { encryptPassword } from '../../lib/crypto';
import { routes } from '../../lib/routes';

const params = new URLSearchParams(window.location.search);
const token = params.get('token') ?? '';
const email = params.get('email') ?? '';

const form = reactive({
    password: '',
    password_confirmation: '',
});

const showPassword = ref(false);
const showPasswordConfirmation = ref(false);
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
        const res = await resetPasswordWithApi({
            token,
            email,
            password: await encryptPassword(form.password),
            password_confirmation: await encryptPassword(
                form.password_confirmation,
            ),
        });
        successMessage.value = res?.message ?? '密碼已重設，請使用新密碼登入。';
    } catch (error) {
        if (error instanceof AuthApiError) {
            generalError.value = error.message;
            fieldErrors.value = error.fieldErrors;
        } else {
            generalError.value = '重設失敗，請稍後再試。';
        }
    } finally {
        isSubmitting.value = false;
    }
}
</script>

<template>
    <Head title="重設密碼" />

    <AuthShell
        eyebrow="> 重設密碼"
        title="設定新密碼"
        summary="請輸入新密碼，密碼須包含大小寫字母、數字及符號，且至少 8 個字元。"
        mode="login"
    >
        <form v-if="!successMessage" class="space-y-8" @submit.prevent="submit">
            <div class="space-y-2">
                <label
                    class="binary-label block text-[11px] font-bold text-[var(--binary-outline)] uppercase"
                    for="password"
                >
                    新密碼 / new password
                </label>
                <div class="relative">
                    <input
                        id="password"
                        v-model="form.password"
                        class="binary-input pr-10"
                        :type="showPassword ? 'text' : 'password'"
                        placeholder="••••••••"
                        autocomplete="new-password"
                        @input="fieldErrors.password = []"
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

            <div class="space-y-2">
                <label
                    class="binary-label block text-[11px] font-bold text-[var(--binary-outline)] uppercase"
                    for="password_confirmation"
                >
                    確認密碼 / confirm password
                </label>
                <div class="relative">
                    <input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        class="binary-input pr-10"
                        :type="showPasswordConfirmation ? 'text' : 'password'"
                        placeholder="••••••••"
                        autocomplete="new-password"
                    />
                    <button
                        type="button"
                        class="absolute inset-y-0 right-3 flex items-center text-[var(--binary-outline)] transition-colors hover:text-[var(--binary-text)]"
                        @click="
                            showPasswordConfirmation = !showPasswordConfirmation
                        "
                    >
                        <svg
                            v-if="showPasswordConfirmation"
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
            </div>

            <p
                v-if="generalError"
                class="border border-red-400/20 bg-red-950/20 px-4 py-3 text-sm text-red-200"
            >
                {{ generalError }}
            </p>

            <div class="pt-4">
                <button
                    class="binary-button"
                    :disabled="isSubmitting"
                    type="submit"
                >
                    {{ isSubmitting ? '更新中...' : '確認重設密碼' }}
                    <span aria-hidden="true">-></span>
                </button>
            </div>
        </form>

        <div v-else class="space-y-6">
            <p
                class="border border-[var(--binary-primary-container)]/20 bg-[var(--binary-primary-container)]/10 px-4 py-3 text-sm text-[var(--binary-primary)]"
            >
                {{ successMessage }}
            </p>
            <Link class="binary-button inline-flex" :href="routes.login()">
                前往登入 <span aria-hidden="true">-></span>
            </Link>
        </div>
    </AuthShell>
</template>

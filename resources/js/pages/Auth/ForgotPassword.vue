<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthShell from '../../layouts/AuthShell.vue';
import { AuthApiError, forgotPasswordWithApi } from '../../lib/auth-api';
import { routes } from '../../lib/routes';

const email = ref('');
const isSubmitting = ref(false);
const generalError = ref('');
const successMessage = ref('');

async function submit() {
    generalError.value = '';
    successMessage.value = '';
    isSubmitting.value = true;

    try {
        const res = await forgotPasswordWithApi({ email: email.value });
        successMessage.value =
            res?.message ?? '如果此信箱已註冊，重設連結已寄出，請查收信件。';
    } catch (error) {
        if (error instanceof AuthApiError) {
            generalError.value = error.message;
        } else {
            generalError.value = '送出失敗，請稍後再試。';
        }
    } finally {
        isSubmitting.value = false;
    }
}
</script>

<template>
    <Head title="忘記密碼" />

    <AuthShell
        eyebrow="> 帳號恢復"
        title="忘記密碼"
        summary="輸入註冊時使用的信箱，系統將寄送密碼重設連結。"
        mode="login"
    >
        <form v-if="!successMessage" class="space-y-8" @submit.prevent="submit">
            <div class="space-y-2">
                <label
                    class="binary-label block text-[11px] font-bold text-[var(--binary-outline)] uppercase"
                    for="email"
                >
                    電子郵件 / email
                </label>
                <input
                    id="email"
                    v-model="email"
                    class="binary-input"
                    type="email"
                    placeholder="root@terminal.dev"
                    autocomplete="email"
                    required
                />
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
                    {{ isSubmitting ? '送出中...' : '傳送重設連結' }}
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
        </div>

        <div class="mt-8 border-t border-[rgba(59,75,55,0.18)] pt-8">
            <div class="flex items-center gap-2 text-sm">
                <span class="text-[var(--binary-text-muted)]"
                    >想起密碼了？</span
                >
                <Link
                    class="font-semibold text-[var(--binary-primary)] transition hover:underline"
                    :href="routes.home()"
                >
                    返回登入
                </Link>
            </div>
        </div>
    </AuthShell>
</template>

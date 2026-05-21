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
            password_confirmation: await encryptPassword(form.password_confirmation),
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
                <label class="binary-label block text-[11px] font-bold uppercase text-[var(--binary-outline)]" for="password">
                    新密碼 / new password
                </label>
                <input
                    id="password"
                    v-model="form.password"
                    class="binary-input"
                    type="password"
                    placeholder="••••••••"
                    autocomplete="new-password"
                    @input="fieldErrors.password = []"
                >
                <p v-if="fieldErrors.password?.length" class="text-xs text-red-300">
                    {{ fieldErrors.password[0] }}
                </p>
            </div>

            <div class="space-y-2">
                <label class="binary-label block text-[11px] font-bold uppercase text-[var(--binary-outline)]" for="password_confirmation">
                    確認密碼 / confirm password
                </label>
                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    class="binary-input"
                    type="password"
                    placeholder="••••••••"
                    autocomplete="new-password"
                >
            </div>

            <p v-if="generalError" class="border border-red-400/20 bg-red-950/20 px-4 py-3 text-sm text-red-200">
                {{ generalError }}
            </p>

            <div class="pt-4">
                <button class="binary-button" :disabled="isSubmitting" type="submit">
                    {{ isSubmitting ? '更新中...' : '確認重設密碼' }}
                    <span aria-hidden="true">-></span>
                </button>
            </div>
        </form>

        <div v-else class="space-y-6">
            <p class="border border-[var(--binary-primary-container)]/20 bg-[var(--binary-primary-container)]/10 px-4 py-3 text-sm text-[var(--binary-primary)]">
                {{ successMessage }}
            </p>
            <Link class="binary-button inline-flex" :href="routes.login()">
                前往登入 <span aria-hidden="true">-></span>
            </Link>
        </div>
    </AuthShell>
</template>

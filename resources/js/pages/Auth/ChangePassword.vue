<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import AuthShell from '../../layouts/AuthShell.vue';
import { AuthApiError, changePasswordWithApi } from '../../lib/auth-api';
import { encryptPassword } from '../../lib/crypto';
import { routes } from '../../lib/routes';

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
        successMessage.value = res?.message ?? '密碼已成功更新';
        form.current_password = '';
        form.password = '';
        form.password_confirmation = '';
    } catch (error) {
        if (error instanceof AuthApiError) {
            generalError.value = error.message;
            fieldErrors.value = error.fieldErrors;
        } else {
            generalError.value = '更新失敗，請稍後再試。';
        }
    } finally {
        isSubmitting.value = false;
    }
}
</script>

<template>
    <Head title="修改密碼" />

    <AuthShell
        eyebrow="> 帳號安全"
        title="修改密碼"
        summary="請輸入目前密碼與新密碼。新密碼須包含大小寫字母、數字及符號，且至少 8 個字元。"
        mode="login"
    >
        <form class="space-y-8" @submit.prevent="submit">
            <div class="space-y-2">
                <label
                    class="binary-label block text-[11px] font-bold text-[var(--binary-outline)] uppercase"
                    for="current_password"
                >
                    目前密碼 / current password
                </label>
                <input
                    id="current_password"
                    v-model="form.current_password"
                    class="binary-input"
                    type="password"
                    placeholder="••••••••"
                    autocomplete="current-password"
                    @input="fieldErrors.current_password = []"
                />
                <p
                    v-if="fieldErrors.current_password?.length"
                    class="text-xs text-red-300"
                >
                    {{ fieldErrors.current_password[0] }}
                </p>
            </div>

            <div class="space-y-2">
                <label
                    class="binary-label block text-[11px] font-bold text-[var(--binary-outline)] uppercase"
                    for="password"
                >
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
                />
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
                    確認新密碼 / confirm password
                </label>
                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    class="binary-input"
                    type="password"
                    placeholder="••••••••"
                    autocomplete="new-password"
                />
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

            <div class="pt-4">
                <button
                    class="binary-button"
                    :disabled="isSubmitting"
                    type="submit"
                >
                    {{ isSubmitting ? '更新中...' : '確認修改密碼' }}
                    <span aria-hidden="true">-></span>
                </button>
            </div>
        </form>

        <div class="mt-8 border-t border-[rgba(59,75,55,0.18)] pt-8">
            <div class="flex items-center gap-2 text-sm">
                <Link
                    class="font-semibold text-[var(--binary-text-muted)] transition hover:text-[var(--binary-primary)]"
                    :href="routes.home()"
                >
                    ← 返回首頁
                </Link>
            </div>
        </div>
    </AuthShell>
</template>

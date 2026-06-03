<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import { AuthApiError, loginWithApi } from '../../lib/auth-api';
import { encryptPassword } from '../../lib/crypto';
import { routes } from '../../lib/routes';
import Turnstile from '../common/Turnstile.vue';

const { t } = useI18n();
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
const fieldErrors = ref<Record<string, string[]>>({});

async function submit() {
    generalError.value = '';
    fieldErrors.value = {};
    isSubmitting.value = true;

    try {
        const response = await loginWithApi({
            email: form.email,
            password: await encryptPassword(form.password),
            remember: form.remember,
            cf_turnstile_response: form.cf_turnstile_response ?? undefined,
        });
        window.location.href = response.redirect || '/';
    } catch (error) {
        if (error instanceof AuthApiError) {
            generalError.value = error.message;
            fieldErrors.value = error.fieldErrors;
        } else if (error instanceof Error) {
            generalError.value = error.message;
        } else {
            generalError.value = t('auth.submit_login') + ' 失敗，請稍後再試。';
        }
    } finally {
        isSubmitting.value = false;
    }
}
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <div class="space-y-1.5">
            <label
                class="binary-label block text-[11px] font-bold text-[var(--binary-outline)] uppercase"
                for="lf-email"
            >
                {{ t('auth.label_email') }}
            </label>
            <input
                id="lf-email"
                v-model="form.email"
                class="binary-input"
                type="email"
                placeholder="root@terminal.dev"
                autocomplete="username"
            />
            <p
                v-if="fieldErrors.email?.length"
                class="text-xs text-[var(--binary-tertiary)]"
            >
                {{ fieldErrors.email[0] }}
            </p>
        </div>

        <div class="space-y-1.5">
            <div class="flex items-center justify-between">
                <label
                    class="binary-label block text-[11px] font-bold text-[var(--binary-outline)] uppercase"
                    for="lf-password"
                >
                    {{ t('auth.label_password') }}
                </label>
                <Link
                    class="binary-label text-[10px] text-[var(--binary-outline)] uppercase transition hover:text-[var(--binary-primary)]"
                    :href="routes.forgotPassword()"
                >
                    {{ t('auth.forgot_password') }}
                </Link>
            </div>
            <div class="relative">
                <input
                    id="lf-password"
                    v-model="form.password"
                    class="binary-input pr-10"
                    :type="showPassword ? 'text' : 'password'"
                    placeholder="••••••••"
                    autocomplete="current-password"
                />
                <button
                    type="button"
                    class="absolute inset-y-0 right-3 flex items-center text-[var(--binary-outline)] transition-colors hover:text-[var(--binary-text)]"
                    @click="showPassword = !showPassword"
                >
                    <svg
                        v-if="showPassword"
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
                class="text-xs text-[var(--binary-tertiary)]"
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
                type="checkbox"
            />
            <span>{{ t('auth.remember_me') }}</span>
        </label>

        <p
            v-if="generalError"
            class="border border-red-400/20 bg-red-950/20 px-4 py-3 text-sm text-red-200"
        >
            {{ generalError }}
        </p>

        <button class="binary-button" :disabled="isSubmitting" type="submit">
            {{ isSubmitting ? t('auth.submitting') : t('auth.submit_login') }}
            <span aria-hidden="true">-></span>
        </button>

        <div v-if="turnstileEnabled" class="mt-2">
            <Turnstile v-model="form.cf_turnstile_response" />
            <p
                v-if="fieldErrors.cf_turnstile_response?.length"
                class="mt-1 text-sm text-[var(--binary-tertiary)]"
            >
                {{ fieldErrors.cf_turnstile_response[0] }}
            </p>
        </div>
    </form>
</template>

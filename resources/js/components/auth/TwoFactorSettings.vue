<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, nextTick, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import { useAuth } from '../../composables/useAuth';
import {
    AuthApiError,
    confirmTwoFactorWithApi,
    disableTwoFactorWithApi,
    enableTwoFactorWithApi,
    regenerateRecoveryCodesWithApi,
} from '../../lib/auth-api';
import { encryptPassword } from '../../lib/crypto';
import type { TwoFactorCredentialPayload } from '../../types';

const { t } = useI18n();
const { user } = useAuth();

// confirm 成功後先以本地旗標標記啟用：顯示備援碼期間不可觸發 router.reload
//（頁面非 persistent layout，assets 版本不匹配時 reload 會整頁重載，一次性備援碼就此蒸發）
const confirmedLocally = ref(false);
const enabled = computed(
    () => !!user.value?.two_factor_enabled || confirmedLocally.value,
);

// idle：顯示狀態；qr：掃描綁定 + 輸入 OTP；codes：一次性顯示備援碼
const phase = ref<'idle' | 'qr' | 'codes'>('idle');
const generalError = ref('');
const fieldErrors = ref<Record<string, string[]>>({});

// ── 綁定（enable → 掃 QR → confirm） ─────────────────────────
const secret = ref('');
const qrCanvas = ref<HTMLCanvasElement | null>(null);
const otpCode = ref('');
const isLoading = ref(false);

async function startEnable() {
    generalError.value = '';
    fieldErrors.value = {};
    isLoading.value = true;

    try {
        const res = await enableTwoFactorWithApi();
        secret.value = res.secret;
        otpCode.value = '';
        phase.value = 'qr';
        await nextTick();

        if (qrCanvas.value) {
            // qrcode 只在進入 2FA 綁定時才需要，動態載入避免進主 bundle
            const QRCode = (await import('qrcode')).default;
            // QR 黑白為可掃描性要求（quiet zone 需白底），為主題色規範的例外
            await QRCode.toCanvas(qrCanvas.value, res.otpauth_uri, {
                width: 192,
                margin: 2,
                color: { dark: '#000000', light: '#ffffff' },
            });
        }
    } catch (error) {
        generalError.value =
            error instanceof AuthApiError
                ? error.message
                : t('profile.twofa_error_failed');
        phase.value = 'idle';
    } finally {
        isLoading.value = false;
    }
}

async function cancelPending() {
    // pending 的 secret 尚未生效，後端允許免憑證停用
    try {
        await disableTwoFactorWithApi({});
    } catch {
        // 取消失敗不擋 UI，殘留 pending secret 下次 enable 會被覆寫
    }

    phase.value = 'idle';
    generalError.value = '';
    fieldErrors.value = {};
}

const recoveryCodes = ref<string[]>([]);

async function submitConfirm() {
    generalError.value = '';
    fieldErrors.value = {};
    isLoading.value = true;

    try {
        const res = await confirmTwoFactorWithApi({ code: otpCode.value });
        recoveryCodes.value = res.recovery_codes;
        confirmedLocally.value = true;
        phase.value = 'codes';
        // auth reload 延後到 finishCodes：備援碼只顯示這一次，期間不能冒任何重繪風險
    } catch (error) {
        if (error instanceof AuthApiError) {
            generalError.value = Object.keys(error.fieldErrors).length
                ? ''
                : error.message;
            fieldErrors.value = error.fieldErrors;
        } else {
            generalError.value = t('profile.twofa_error_failed');
        }
    } finally {
        isLoading.value = false;
    }
}

// ── 備援碼顯示 ───────────────────────────────────────────────
const copied = ref(false);

async function copyCodes() {
    await navigator.clipboard.writeText(recoveryCodes.value.join('\n'));
    copied.value = true;
    setTimeout(() => {
        copied.value = false;
    }, 1000);
}

function finishCodes() {
    recoveryCodes.value = [];
    phase.value = 'idle';
    router.reload({ only: ['auth'] });
}

// ── 停用 / 重生備援碼（需密碼或 OTP 擇一） ─────────────────────
const showCredentialModal = ref(false);
const credentialMode = ref<'disable' | 'regenerate'>('disable');
const credential = reactive({ password: '', code: '' });
const credentialError = ref('');
const credentialLoading = ref(false);

function openCredentialModal(mode: 'disable' | 'regenerate') {
    credentialMode.value = mode;
    credential.password = '';
    credential.code = '';
    credentialError.value = '';
    showCredentialModal.value = true;
}

function closeCredentialModal() {
    showCredentialModal.value = false;
}

async function submitCredential() {
    if (!credential.password && !credential.code) {
        credentialError.value = t('profile.twofa_credential_required');

        return;
    }

    credentialError.value = '';
    credentialLoading.value = true;

    try {
        // 兩欄皆填就都送（後端擇一有效即可），避免密碼管理器自動填入造成誤判
        const payload: TwoFactorCredentialPayload = {};

        if (credential.password) {
            payload.password = await encryptPassword(credential.password);
        }

        if (credential.code) {
            payload.code = credential.code;
        }

        if (credentialMode.value === 'disable') {
            await disableTwoFactorWithApi(payload);
            showCredentialModal.value = false;
            confirmedLocally.value = false;
            phase.value = 'idle';
            router.reload({ only: ['auth'] });
        } else {
            const res = await regenerateRecoveryCodesWithApi(payload);
            recoveryCodes.value = res.recovery_codes;
            showCredentialModal.value = false;
            phase.value = 'codes';
        }
    } catch (error) {
        if (error instanceof AuthApiError) {
            credentialError.value =
                error.fieldErrors.credential?.[0] ?? error.message;
        } else {
            credentialError.value = t('profile.twofa_error_failed');
        }
    } finally {
        credentialLoading.value = false;
    }
}
</script>

<template>
    <div class="space-y-4">
        <!-- 狀態列 -->
        <div class="flex items-center justify-between">
            <span class="text-sm font-bold text-[var(--binary-text)]">
                {{ t('profile.twofa_title') }}
            </span>
            <span
                class="binary-label text-[10px] font-bold uppercase"
                :class="
                    enabled
                        ? 'text-[var(--binary-primary)]'
                        : 'text-[var(--binary-outline)]'
                "
            >
                {{
                    enabled
                        ? t('profile.twofa_status_on')
                        : t('profile.twofa_status_off')
                }}
            </span>
        </div>

        <!-- idle：說明 + 主操作 -->
        <template v-if="phase === 'idle'">
            <p class="text-xs leading-relaxed text-[var(--binary-text-muted)]">
                {{ t('profile.twofa_intro') }}
            </p>

            <button
                v-if="!enabled"
                class="binary-button"
                :disabled="isLoading"
                type="button"
                @click="startEnable"
            >
                {{
                    isLoading
                        ? t('profile.twofa_enabling')
                        : t('profile.twofa_enable')
                }}
                <span aria-hidden="true">-></span>
            </button>

            <div v-else class="flex flex-wrap gap-2">
                <button
                    class="binary-ghost-button px-4 py-2 text-xs"
                    type="button"
                    @click="openCredentialModal('regenerate')"
                >
                    {{ t('profile.twofa_regenerate') }}
                </button>
                <button
                    class="rounded border border-[var(--binary-tertiary)]/30 px-4 py-2 text-xs text-[var(--binary-tertiary)] transition-colors hover:bg-[var(--binary-tertiary)]/10"
                    type="button"
                    @click="openCredentialModal('disable')"
                >
                    {{ t('profile.twofa_disable') }}
                </button>
            </div>
        </template>

        <!-- qr：掃描 + 輸入 OTP 確認 -->
        <template v-if="phase === 'qr'">
            <p class="text-xs leading-relaxed text-[var(--binary-text-muted)]">
                {{ t('profile.twofa_scan_hint') }}
            </p>
            <!-- 白底 wrapper：QR quiet zone 需白底才掃得到（主題色規範例外） -->
            <div class="flex justify-center">
                <div class="rounded bg-white p-3">
                    <canvas ref="qrCanvas" />
                </div>
            </div>
            <div class="space-y-1">
                <p class="text-xs text-[var(--binary-text-muted)]">
                    {{ t('profile.twofa_manual_hint') }}
                </p>
                <code
                    class="block font-mono text-xs break-all text-[var(--binary-primary)] select-all"
                    >{{ secret }}</code
                >
            </div>
            <form class="space-y-3" @submit.prevent="submitConfirm">
                <div class="space-y-1.5">
                    <label
                        class="binary-label block text-[11px] font-bold text-[var(--binary-outline)] uppercase"
                        for="tf-code"
                    >
                        {{ t('profile.twofa_code_label') }}
                    </label>
                    <input
                        id="tf-code"
                        v-model="otpCode"
                        class="binary-input font-mono tracking-[0.3em]"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="6"
                        placeholder="000000"
                    />
                    <p
                        v-if="fieldErrors.code?.length"
                        class="text-xs text-[var(--binary-tertiary)]"
                    >
                        {{ fieldErrors.code[0] }}
                    </p>
                </div>
                <div class="flex gap-2">
                    <button
                        class="binary-ghost-button flex-1 py-2 text-xs"
                        type="button"
                        @click="cancelPending"
                    >
                        {{ t('profile.twofa_cancel') }}
                    </button>
                    <button
                        class="binary-button flex-1 py-2 text-xs"
                        :disabled="isLoading || otpCode.length < 6"
                        type="submit"
                    >
                        {{
                            isLoading
                                ? t('profile.twofa_confirming')
                                : t('profile.twofa_confirm')
                        }}
                    </button>
                </div>
            </form>
        </template>

        <!-- codes：一次性顯示備援碼 -->
        <template v-if="phase === 'codes'">
            <div
                class="rounded-lg border border-[var(--binary-tertiary)]/30 bg-[var(--binary-tertiary)]/5 p-3"
            >
                <p
                    class="mb-2 text-xs font-bold tracking-wider text-[var(--binary-tertiary)]"
                >
                    {{ t('profile.twofa_codes_hint') }}
                </p>
                <div class="grid grid-cols-2 gap-x-4 gap-y-1.5">
                    <code
                        v-for="code in recoveryCodes"
                        :key="code"
                        class="font-mono text-xs text-[var(--binary-text)] select-all"
                        >{{ code }}</code
                    >
                </div>
            </div>
            <div class="flex gap-2">
                <button
                    class="binary-ghost-button flex-1 py-2 text-xs"
                    type="button"
                    @click="copyCodes"
                >
                    {{
                        copied
                            ? t('profile.apikey_copied')
                            : t('profile.twofa_copy_all')
                    }}
                </button>
                <button
                    class="binary-button flex-1 py-2 text-xs"
                    type="button"
                    @click="finishCodes"
                >
                    {{ t('profile.twofa_codes_saved') }}
                </button>
            </div>
        </template>

        <p
            v-if="generalError"
            class="border border-[var(--binary-tertiary)]/20 bg-[var(--binary-tertiary)]/10 px-4 py-3 text-sm text-[var(--binary-tertiary)]"
        >
            {{ generalError }}
        </p>
    </div>

    <!-- 停用 / 重生備援碼：憑證確認 Modal -->
    <Teleport to="body">
        <div
            v-if="showCredentialModal"
            class="fixed inset-0 z-[80] flex items-center justify-center bg-black/60 backdrop-blur-sm"
            @click.self="closeCredentialModal"
        >
            <div
                class="mx-4 w-full max-w-md rounded-xl border border-[var(--binary-outline-variant)] bg-[var(--binary-surface)] p-6 shadow-2xl"
            >
                <div class="mb-2 text-base font-bold">
                    {{
                        credentialMode === 'disable'
                            ? t('profile.twofa_disable')
                            : t('profile.twofa_regenerate')
                    }}
                </div>
                <p class="mb-4 text-xs text-[var(--binary-text-muted)]">
                    {{ t('profile.twofa_credential_hint') }}
                </p>
                <div class="mb-4">
                    <label
                        class="mb-1.5 block text-xs text-[var(--binary-outline)]"
                        for="tf-cred-password"
                        >{{ t('profile.twofa_credential_password') }}</label
                    >
                    <input
                        id="tf-cred-password"
                        v-model="credential.password"
                        class="binary-input w-full text-sm"
                        type="password"
                        placeholder="••••••••"
                        autocomplete="current-password"
                    />
                </div>
                <div class="mb-5">
                    <label
                        class="mb-1.5 block text-xs text-[var(--binary-outline)]"
                        for="tf-cred-code"
                        >{{ t('profile.twofa_credential_code') }}</label
                    >
                    <input
                        id="tf-cred-code"
                        v-model="credential.code"
                        class="binary-input w-full font-mono text-sm"
                        type="text"
                        inputmode="numeric"
                        maxlength="6"
                        placeholder="000000"
                    />
                </div>
                <div
                    v-if="credentialError"
                    class="mb-3 text-xs text-[var(--binary-tertiary)]"
                >
                    {{ credentialError }}
                </div>
                <div class="flex gap-2">
                    <button
                        class="flex-1 rounded border border-[var(--binary-outline-variant)] py-2 text-xs text-[var(--binary-outline)] hover:border-[var(--binary-outline)]"
                        type="button"
                        @click="closeCredentialModal"
                    >
                        {{ t('profile.twofa_cancel') }}
                    </button>
                    <button
                        class="binary-button flex-1 py-2 text-xs"
                        :disabled="credentialLoading"
                        type="button"
                        @click="submitCredential"
                    >
                        {{
                            credentialLoading
                                ? t('profile.submitting')
                                : t('profile.twofa_credential_submit')
                        }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

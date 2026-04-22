<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';

const DEFAULTS = {
    site_name: 'BINARY_EDITORIAL',
    maintenance_mode: false,
    allow_registration: true,
    max_login_attempts: 5,
    avatar_size: 128,
};

const form = ref({ ...DEFAULTS });
const isSaving = ref(false);
const isLoading = ref(true);
const successMessage = ref('');
const errorMessage = ref('');
const page = usePage();

onMounted(async () => {
    if (!page.props.auth?.user) {
        router.visit('/');

        return;
    }

    try {
        const res = await fetch('/api/admin/settings', {
            credentials: 'include',
            headers: { Accept: 'application/json' },
        });

        if (res.status === 401 || res.status === 403) {
            router.visit('/');

            return;
        }

        const data = await res.json();
        form.value = { ...DEFAULTS, ...data };
    } catch {
        errorMessage.value = '載入設定失敗';
    } finally {
        isLoading.value = false;
    }
});

async function save() {
    if (isSaving.value) {
return;
}

    if (!page.props.auth?.user) {
 router.visit('/');

 return; 
}

    isSaving.value = true;
    successMessage.value = '';
    errorMessage.value = '';

    try {
        const response = await fetch('/api/admin/settings', {
            method: 'PATCH',
            credentials: 'include',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(form.value),
        });

        const data = await response.json();

        if (!response.ok) {
            errorMessage.value = data.message || '儲存失敗';

            return;
        }

        form.value = { ...DEFAULTS, ...data.settings };
        successMessage.value = data.message || '設定已更新';
    } catch {
        errorMessage.value = '連線異常，請稍後再試';
    } finally {
        isSaving.value = false;
    }
}
</script>

<template>
    <Head title="Admin Settings" />

    <div class="binary-page min-h-screen">
        <div class="mx-auto max-w-2xl px-6 py-16">
            <div class="mb-10">
                <span class="binary-label mb-2 block text-xs font-bold uppercase text-[var(--binary-primary)]">&gt; admin_console</span>
                <h1 class="binary-display text-4xl font-black uppercase tracking-tight">系統設定</h1>
                <p class="mt-2 text-sm text-[var(--binary-text-muted)]">
                    變更後立即生效。設定以 Cache 儲存，重新部署前不會遺失。
                </p>
            </div>

            <div v-if="isLoading" class="py-12 text-center text-sm text-[var(--binary-text-muted)]">載入中...</div>

            <form v-else class="space-y-6" @submit.prevent="save">
                <div class="binary-card-raised rounded-2xl space-y-6">
                    <div>
                        <label class="binary-label mb-2 block text-[11px] font-bold uppercase text-[var(--binary-outline)]">
                            站台名稱
                        </label>
                        <input
                            v-model="form.site_name"
                            class="binary-input"
                            type="text"
                            placeholder="BINARY_EDITORIAL"
                        >
                    </div>

                    <div>
                        <label class="binary-label mb-2 block text-[11px] font-bold uppercase text-[var(--binary-outline)]">
                            最大登入失敗次數
                        </label>
                        <input
                            v-model.number="form.max_login_attempts"
                            class="binary-input"
                            type="number"
                            min="1"
                            max="20"
                        >
                    </div>

                    <div>
                        <label class="binary-label mb-2 block text-[11px] font-bold uppercase text-[var(--binary-outline)]">
                            頭像尺寸
                        </label>
                        <select v-model.number="form.avatar_size" class="binary-input">
                            <option :value="64">64px</option>
                            <option :value="128">128px</option>
                            <option :value="256">256px</option>
                        </select>
                    </div>

                    <div class="flex flex-col gap-4">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input
                                v-model="form.allow_registration"
                                type="checkbox"
                                class="h-4 w-4 border-0 bg-[var(--binary-surface-high)] text-[var(--binary-primary-container)] focus:ring-0"
                            >
                            <span class="binary-label text-xs uppercase text-[var(--binary-text)]">開放使用者自行註冊</span>
                        </label>

                        <label class="flex items-center gap-3 cursor-pointer">
                            <input
                                v-model="form.maintenance_mode"
                                type="checkbox"
                                class="h-4 w-4 border-0 bg-[var(--binary-surface-high)] text-[var(--binary-primary-container)] focus:ring-0"
                            >
                            <span class="binary-label text-xs uppercase text-[var(--binary-text)]">維護模式</span>
                        </label>
                    </div>
                </div>

                <p v-if="errorMessage" class="border border-red-400/20 bg-red-950/20 px-4 py-3 text-sm text-red-200">
                    {{ errorMessage }}
                </p>

                <p v-if="successMessage" class="border border-[var(--binary-primary-container)]/20 bg-[var(--binary-primary-container)]/10 px-4 py-3 text-sm text-[var(--binary-primary)]">
                    {{ successMessage }}
                </p>

                <div class="flex items-center justify-between">
                    <button
                        type="button"
                        class="binary-ghost-button text-xs"
                        @click="router.visit('/')"
                    >
                        ← 返回首頁
                    </button>
                    <button
                        class="binary-button"
                        :disabled="isSaving"
                        type="submit"
                    >
                        {{ isSaving ? '儲存中...' : '儲存設定' }}
                        <span aria-hidden="true">-></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

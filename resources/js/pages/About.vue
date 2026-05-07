<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { api, routes } from '@/lib/routes';
import { useAuth } from '@/composables/useAuth';

const { t } = useI18n();

interface Turn {
    role: 'user' | 'model';
    text: string;
}

const { user, isLoggedIn, isAdmin } = useAuth();

// ── Chat state ───────────────────────────────────────────
const history = ref<Turn[]>([]);
const input = ref('');
const isSending = ref(false);
const errorMessage = ref('');
const chatBox = ref<HTMLElement | null>(null);

const QUICK_QUESTIONS = [
    '你的技術專長是什麼？',
    '你做過哪些專案？',
    '你為什麼選擇後端工程師？',
    '你如何面對技術挑戰？',
];

async function send(text?: string) {
    const message = (text ?? input.value).trim();
    if (!message || isSending.value) return;

    input.value = '';
    errorMessage.value = '';
    history.value.push({ role: 'user', text: message });
    isSending.value = true;
    await scrollToBottom();

    try {
        const res = await fetch(api.about.ask(), {
            method: 'POST',
            credentials: 'include',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ message, history: history.value.slice(0, -1) }),
        });
        const data = await res.json();

        if (!res.ok) {
            errorMessage.value = data.message ?? '發生錯誤，請稍後再試';
            history.value.pop();
            return;
        }

        history.value.push({ role: 'model', text: data.reply });
    } catch {
        errorMessage.value = '連線失敗，請稍後再試';
        history.value.pop();
    } finally {
        isSending.value = false;
        await scrollToBottom();
    }
}

async function scrollToBottom() {
    await nextTick();
    if (chatBox.value) {
        chatBox.value.scrollTop = chatBox.value.scrollHeight;
    }
}

// ── Admin: context management ─────────────────────────────
const contextText = ref('');
const isLoadingContext = ref(false);
const isSavingContext = ref(false);
const contextMessage = ref('');
const showContextPanel = ref(false);

async function loadContext() {
    isLoadingContext.value = true;
    try {
        const res = await fetch('/api/about/context', {
            credentials: 'include',
            headers: { Accept: 'application/json' },
        });
        const data = await res.json();
        contextText.value = data.context ?? '';
    } finally {
        isLoadingContext.value = false;
    }
}

async function saveContext() {
    isSavingContext.value = true;
    contextMessage.value = '';
    try {
        const res = await fetch('/api/about/context', {
            method: 'PUT',
            credentials: 'include',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ context: contextText.value }),
        });
        const data = await res.json();
        contextMessage.value = res.ok ? '已儲存' : (data.message ?? '儲存失敗');
    } finally {
        isSavingContext.value = false;
    }
}

function toggleContextPanel() {
    showContextPanel.value = !showContextPanel.value;
    if (showContextPanel.value && contextText.value === '') {
        loadContext();
    }
}
</script>

<template>
    <Head title="About" />

    <AppLayout>
            <template v-if="!isLoggedIn">
                <div class="flex flex-col items-center justify-center min-h-[60vh] bg-[var(--binary-surface)]">
                    <div
                        class="w-full max-w-xl px-10 py-12"
                        style="background:rgba(15,21,17,0.92);backdrop-filter:blur(20px);border-radius:2rem;box-shadow:0 8px 32px 0 rgba(107,220,159,0.10);"
                    >
                        <h2 class="text-[2rem] font-bold mb-4 text-[var(--binary-primary)] tracking-tight text-left" style="letter-spacing:-1px;">{{ t('about.auth_required_title') }}</h2>
                        <p class="text-[var(--binary-text)] text-lg mb-8 text-left leading-relaxed">{{ t('about.auth_required_body') }}</p>
                        <div class="flex justify-end">
                            <a :href="routes.login()"
                                class="px-8 py-3 rounded-md text-base font-semibold"
                                style="background:linear-gradient(145deg,var(--binary-primary),var(--binary-primary-container));color:var(--binary-on-primary-container);box-shadow:0 2px 8px 0 rgba(107,220,159,0.10);"
                            >{{ t('about.auth_required_action') }}</a>
                        </div>
                    </div>
                </div>
            </template>
            <template v-else-if="user && !user.email_verified_at">
                <div class="flex flex-col items-center justify-center min-h-[60vh] bg-[var(--binary-surface)]">
                    <div
                        class="w-full max-w-xl px-10 py-12"
                        style="background:rgba(15,21,17,0.92);backdrop-filter:blur(20px);border-radius:2rem;box-shadow:0 8px 32px 0 rgba(255,179,178,0.10);"
                    >
                        <h2 class="text-[2rem] font-bold mb-4 text-[var(--binary-tertiary)] tracking-tight text-left" style="letter-spacing:-1px;">{{ t('about.verify_required_title') }}</h2>
                        <p class="text-[var(--binary-text)] text-lg mb-8 text-left leading-relaxed">{{ t('about.verify_required_body') }}</p>
                        <div class="flex justify-end">
                            <a :href="routes.home()"
                                class="px-8 py-3 rounded-md text-base font-semibold"
                                style="background:linear-gradient(145deg,var(--binary-primary),var(--binary-primary-container));color:var(--binary-on-primary-container);box-shadow:0 2px 8px 0 rgba(107,220,159,0.10);"
                            >{{ t('about.verify_required_action') }}</a>
                        </div>
                    </div>
                </div>
            </template>
            <template v-else>
            <main class="pt-24 pb-24">
                <section class="mx-auto max-w-screen-xl px-6 py-12 md:px-8">
                    <!-- Header -->
                    <div class="mb-8">
                        <span class="binary-label mb-2 block text-xs font-bold uppercase text-[var(--binary-primary)]">&gt; about_me</span>
                        <div class="flex items-end justify-between gap-4">
                            <h1 class="binary-display text-5xl font-black uppercase tracking-tight md:text-6xl">Ask Me</h1>
                            <button
                                v-if="isAdmin"
                                class="binary-ghost-button px-4 py-1.5 text-xs"
                                @click="toggleContextPanel"
                            >
                                {{ showContextPanel ? '關閉 Context' : '匯入 Context' }}
                            </button>
                        </div>
                        <p class="mt-3 text-sm text-[var(--binary-text-muted)]">
                            歡迎問我任何關於技術背景、專案經歷或工作風格的問題。
                        </p>
                    </div>
                    <!-- Admin: Context Panel -->
                    <div v-if="isAdmin && showContextPanel" class="binary-card-raised mb-8 rounded-2xl space-y-4">
                        <h2 class="binary-label text-xs font-bold uppercase tracking-widest text-[var(--binary-outline)]">
                            &gt; resume_context（管理員）
                        </h2>
                        <p class="text-xs text-[var(--binary-text-muted)]">
                            貼入你的履歷或背景資料，Gemini 將依此內容回答訪客問題。
                        </p>
                        <div v-if="isLoadingContext" class="text-xs text-[var(--binary-text-muted)]">載入中...</div>
                        <textarea
                            v-else
                            v-model="contextText"
                            rows="12"
                            placeholder="貼入履歷、專案介紹、技術背景、人格特質等..."
                            class="binary-input w-full resize-y font-mono text-sm"
                        />
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-xs" :class="contextMessage === '已儲存' ? 'text-[var(--binary-primary)]' : 'text-red-400'">
                                {{ contextMessage }}
                            </span>
                            <button
                                class="binary-button"
                                :class="{ 'opacity-50 cursor-not-allowed': isSavingContext }"
                                :disabled="isSavingContext"
                                @click="saveContext"
                            >
                                {{ isSavingContext ? '儲存中...' : '儲存 Context' }}
                            </button>
                        </div>
                    </div>
                    <!-- Quick Questions -->
                    <div class="mb-6 flex flex-wrap gap-2">
                        <button
                            v-for="q in QUICK_QUESTIONS"
                            :key="q"
                            class="binary-label rounded-lg bg-[var(--binary-surface-container)] px-3 py-1.5 text-[10px] uppercase text-[var(--binary-outline)] transition hover:text-[var(--binary-primary)]"
                            @click="send(q)"
                        >
                            {{ q }}
                        </button>
                    </div>
                    <!-- Chat Box -->
                    <div class="binary-card-raised rounded-2xl">
                        <div
                            ref="chatBox"
                            class="mb-4 max-h-[480px] min-h-[200px] overflow-y-auto space-y-4"
                        >
                            <div v-if="history.length === 0" class="py-12 text-center text-sm text-[var(--binary-text-muted)]">
                                請輸入問題或點選上方快捷按鈕開始對話。
                            </div>
                            <div
                                v-for="(turn, i) in history"
                                :key="i"
                                class="flex"
                                :class="turn.role === 'user' ? 'justify-end' : 'justify-start'"
                            >
                                <div
                                    class="max-w-[75%] rounded-2xl px-4 py-3 text-sm leading-relaxed"
                                    :class="turn.role === 'user'
                                        ? 'bg-[var(--binary-primary)] text-[var(--binary-on-primary-container)]'
                                        : 'bg-[var(--binary-surface-container)] text-[var(--binary-text)]'"
                                >
                                    {{ turn.text }}
                                </div>
                            </div>
                            <div v-if="isSending" class="flex justify-start">
                                <div class="rounded-2xl bg-[var(--binary-surface-container)] px-4 py-3">
                                    <span class="inline-flex gap-1">
                                        <span class="inline-block h-1.5 w-1.5 animate-bounce rounded-full bg-[var(--binary-outline)]" style="animation-delay:0ms" />
                                        <span class="inline-block h-1.5 w-1.5 animate-bounce rounded-full bg-[var(--binary-outline)]" style="animation-delay:150ms" />
                                        <span class="inline-block h-1.5 w-1.5 animate-bounce rounded-full bg-[var(--binary-outline)]" style="animation-delay:300ms" />
                                    </span>
                                </div>
                            </div>
                        </div>
                        <p v-if="errorMessage" class="mb-3 text-xs text-red-400">{{ errorMessage }}</p>
                        <div class="flex items-center gap-3">
                            <input
                                v-model="input"
                                type="text"
                                maxlength="500"
                                placeholder="輸入問題..."
                                class="binary-input min-w-0 flex-1"
                                @keydown.enter.prevent="send()"
                            >
                            <button
                                class="binary-button !w-auto shrink-0 px-4 py-2 text-xs"
                                :class="{ 'opacity-50 cursor-not-allowed': isSending || !input.trim() }"
                                :disabled="isSending || !input.trim()"
                                @click="send()"
                            >
                                問
                            </button>
                        </div>
                    </div>
                </section>
            </main>
        </template>
    </AppLayout>
</template>

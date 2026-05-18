<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';

import { useAuth } from '@/composables/useAuth';
import { api, routes } from '@/lib/routes';

// ── Types ─────────────────────────────────────────────────

type SessionListItem = {
    id: number;
    title: string;
    status: 'active' | 'paused' | 'completed';
    content_rating: 'general' | 'mature';
    next_advance_at: string | null;
    updated_at: string;
};

type StoryCharacter = {
    id: number;
    name: string;
    persona: string;
    type: 'llm' | 'player' | 'npc';
    status: 'active' | 'unconscious' | 'captured' | 'dead';
    turn_order: number;
};

type StorySegment = {
    id: number;
    character_id: number | null;
    content: string;
    turn_number: number;
    is_player_written: boolean;
    is_event: boolean;
    character: StoryCharacter | null;
    created_at: string;
};

type StoryItem = {
    id: number;
    name: string;
    description: string;
    holder: StoryCharacter | null;
    is_preset: boolean;
};

type StorySession = SessionListItem & {
    setting: Record<string, unknown>;
    world_state: string;
    advance_interval_minutes: number;
    current_character_id: number | null;
    current_character: StoryCharacter | null;
    characters: StoryCharacter[];
    segments: StorySegment[];
    items: StoryItem[];
};

type SetupDraft = {
    world: string;
    opening: string;
    characters: { name: string; persona: string; secret?: string }[];
    items: { name: string; description: string; holder: string | null }[];
};

// ── Auth ──────────────────────────────────────────────────

const { isAdmin } = useAuth();

// ── State ─────────────────────────────────────────────────

type Panel = 'none' | 'setup' | 'session';

const panel       = ref<Panel>('none');
const sessions    = ref<SessionListItem[]>([]);
const selected    = ref<StorySession | null>(null);
const isLoadingList    = ref(false);
const isLoadingSession = ref(false);
const error       = ref('');

// Setup flow
const setupStep    = ref<0 | 1 | 2>(0);
const keywords     = ref('');
const genre        = ref<'fantasy' | 'mystery' | 'scifi' | 'modern'>('fantasy');
const setupDraft   = ref<SetupDraft | null>(null);
const setupTitle   = ref('');
const setupInterval = ref(30);
const setupRating  = ref<'general' | 'mature'>('general');
const setupLoading = ref(false);
const setupError   = ref('');

// Player turn
const playerTurnContent = ref('');
const isSubmittingTurn  = ref(false);

// Control
const isUpdatingStatus = ref(false);

// ── Polling ───────────────────────────────────────────────

let pollTimer: ReturnType<typeof setInterval> | null = null;

function startPolling() {
    stopPolling();
    pollTimer = setInterval(() => {
        if (selected.value && selected.value.status === 'active') {
            loadSession(selected.value.id, false);
        }
    }, 30_000);
}

function stopPolling() {
    if (pollTimer !== null) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

onBeforeUnmount(stopPolling);

// ── API helpers ───────────────────────────────────────────

async function fetchJson<T>(url: string, init?: RequestInit): Promise<T> {
    const res = await fetch(url, { credentials: 'include', ...init });
    if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error((data as { message?: string }).message ?? `HTTP ${res.status}`);
    }
    return res.json() as Promise<T>;
}

// ── Session list ──────────────────────────────────────────

async function loadSessions() {
    isLoadingList.value = true;
    error.value = '';
    try {
        sessions.value = await fetchJson<SessionListItem[]>(api.story.sessions());
    } catch (e: unknown) {
        error.value = e instanceof Error ? e.message : '載入失敗';
    } finally {
        isLoadingList.value = false;
    }
}

async function loadSession(id: number, showLoading = true) {
    if (showLoading) isLoadingSession.value = true;
    try {
        selected.value = await fetchJson<StorySession>(api.story.session(id));
    } catch {
        // silent on poll
    } finally {
        isLoadingSession.value = false;
    }
}

async function selectSession(item: SessionListItem) {
    panel.value = 'session';
    setupDraft.value = null;
    await loadSession(item.id);
    startPolling();
}

// ── Setup flow ────────────────────────────────────────────

function openSetup() {
    panel.value = 'setup';
    setupStep.value = 0;
    setupDraft.value = null;
    setupTitle.value = '';
    keywords.value = '';
    setupError.value = '';
    stopPolling();
    selected.value = null;
}

async function generateDraft() {
    if (!keywords.value.trim()) return;
    setupLoading.value = true;
    setupError.value = '';
    try {
        const res = await fetchJson<{ setup: SetupDraft }>(api.story.setupGenerate(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ keywords: keywords.value, genre: genre.value }),
        });
        setupDraft.value = res.setup;
        setupTitle.value = `${genre.value} — ${keywords.value.slice(0, 20)}`;
        setupStep.value = 1;
    } catch (e: unknown) {
        setupError.value = e instanceof Error ? e.message : '產生失敗';
    } finally {
        setupLoading.value = false;
    }
}

async function refineDraft() {
    if (!setupDraft.value) return;
    setupLoading.value = true;
    setupError.value = '';
    try {
        const res = await fetchJson<{ setup: SetupDraft }>(api.story.setupRefine(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ setup: setupDraft.value }),
        });
        setupDraft.value = res.setup;
    } catch (e: unknown) {
        setupError.value = e instanceof Error ? e.message : '優化失敗';
    } finally {
        setupLoading.value = false;
    }
}

async function createSession() {
    if (!setupDraft.value || !setupTitle.value.trim()) return;
    setupLoading.value = true;
    setupError.value = '';
    try {
        const body = {
            title: setupTitle.value,
            setting: { world: setupDraft.value.world, opening: setupDraft.value.opening },
            characters: setupDraft.value.characters.map((c, i) => ({
                name: c.name,
                persona: c.persona + (c.secret ? `\n秘密：${c.secret}` : ''),
                type: 'llm',
                turn_order: i,
            })),
            items: setupDraft.value.items ?? [],
            advance_interval_minutes: setupInterval.value,
            content_rating: setupRating.value,
        };

        const session = await fetchJson<StorySession>(api.story.sessions(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });

        await loadSessions();
        panel.value = 'session';
        selected.value = session;
        setupDraft.value = null;
    } catch (e: unknown) {
        setupError.value = e instanceof Error ? e.message : '建立失敗';
    } finally {
        setupLoading.value = false;
    }
}

// ── Session control ───────────────────────────────────────

async function updateStatus(status: 'active' | 'paused' | 'completed') {
    if (!selected.value) return;
    isUpdatingStatus.value = true;
    try {
        await fetchJson(api.story.sessionStatus(selected.value.id), {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status }),
        });
        await loadSession(selected.value.id);
        await loadSessions();
        if (status === 'active') startPolling();
        else stopPolling();
    } catch (e: unknown) {
        error.value = e instanceof Error ? e.message : '更新失敗';
    } finally {
        isUpdatingStatus.value = false;
    }
}

async function submitPlayerTurn() {
    if (!selected.value || !playerTurnContent.value.trim()) return;
    isSubmittingTurn.value = true;
    try {
        await fetchJson(api.story.playerTurn(selected.value.id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ content: playerTurnContent.value }),
        });
        playerTurnContent.value = '';
        await loadSession(selected.value.id);
    } catch (e: unknown) {
        error.value = e instanceof Error ? e.message : '送出失敗';
    } finally {
        isSubmittingTurn.value = false;
    }
}

// ── Helpers ───────────────────────────────────────────────

const statusLabel: Record<string, string> = { active: '進行中', paused: '已暫停', completed: '已完結' };
const statusColor: Record<string, string> = {
    active:    'text-emerald-400',
    paused:    'text-yellow-400',
    completed: 'text-[var(--binary-text-muted)]',
};

const genreOptions = [
    { value: 'fantasy', label: '奇幻' },
    { value: 'mystery', label: '懸疑' },
    { value: 'scifi',   label: '科幻' },
    { value: 'modern',  label: '現代' },
];

function isPlayerTurn(): boolean {
    return (
        !!selected.value &&
        selected.value.status === 'active' &&
        selected.value.current_character?.type === 'player'
    );
}

onMounted(loadSessions);
</script>

<template>
    <AppLayout>
    <Head title="Story Relay" />

    <div class="mx-auto mt-24 flex h-[calc(100vh-6rem)] w-full max-w-screen-2xl overflow-hidden px-6 font-mono md:px-8">

        <!-- ── Left panel: session list ─────────────────── -->
        <aside class="flex w-3/12 flex-col border-r border-[var(--binary-outline)]/20 bg-[var(--binary-surface)]">
            <!-- New button -->
            <div class="border-b border-[var(--binary-outline)]/20 p-4">
                <button
                    class="binary-ghost-button w-full py-2 text-xs uppercase tracking-widest"
                    type="button"
                    @click="openSetup"
                >
                    + 新故事
                </button>
            </div>

            <!-- List -->
            <div class="flex-1 overflow-y-auto p-2">
                <p v-if="isLoadingList" class="p-4 text-center text-xs text-[var(--binary-text-muted)]">載入中…</p>
                <p v-else-if="!sessions.length" class="p-4 text-center text-xs text-[var(--binary-text-muted)]">尚無故事</p>
                <button
                    v-for="s in sessions"
                    :key="s.id"
                    type="button"
                    class="mb-1 w-full rounded-lg px-3 py-2.5 text-left transition"
                    :class="selected?.id === s.id
                        ? 'bg-[var(--binary-outline)]/10 text-[var(--binary-text)]'
                        : 'text-[var(--binary-text-muted)] hover:bg-[var(--binary-surface-container)]'"
                    @click="selectSession(s)"
                >
                    <p class="truncate text-xs font-semibold">{{ s.title }}</p>
                    <p class="mt-0.5 text-[10px]" :class="statusColor[s.status]">
                        {{ statusLabel[s.status] }}
                    </p>
                </button>
            </div>
        </aside>

        <!-- ── Right panel ───────────────────────────────── -->
        <main class="flex w-9/12 flex-col overflow-hidden">

            <!-- Empty state -->
            <div v-if="panel === 'none'" class="flex flex-1 items-center justify-center">
                <p class="binary-label text-xs uppercase text-[var(--binary-text-muted)]">選擇故事或建立新故事</p>
            </div>

            <!-- ── Setup area ──────────────────────────── -->
            <div v-else-if="panel === 'setup'" class="flex flex-1 flex-col overflow-y-auto p-6">
                <h2 class="binary-label mb-6 text-xs uppercase tracking-widest text-[var(--binary-outline)]">新故事設定</h2>

                <p v-if="setupError" class="mb-4 rounded-lg border border-red-400/20 bg-red-950/20 px-4 py-2 text-xs text-red-300">{{ setupError }}</p>

                <!-- Step 0: keywords + genre -->
                <template v-if="setupStep === 0">
                    <div class="mb-4 grid grid-cols-4 gap-2">
                        <button
                            v-for="g in genreOptions"
                            :key="g.value"
                            type="button"
                            class="rounded-lg border px-3 py-2 text-xs transition"
                            :class="genre === g.value
                                ? 'border-[var(--binary-primary)] text-[var(--binary-primary)]'
                                : 'border-[var(--binary-outline)]/30 text-[var(--binary-text-muted)] hover:border-[var(--binary-outline)]'"
                            @click="genre = g.value as typeof genre"
                        >
                            {{ g.label }}
                        </button>
                    </div>
                    <textarea
                        v-model="keywords"
                        class="binary-input mb-4 w-full resize-none"
                        rows="3"
                        placeholder="輸入關鍵字，例如：魔法學院、記憶失竊、雙生兄弟…"
                        maxlength="200"
                    />
                    <div class="flex justify-end">
                        <button
                            class="binary-ghost-button px-6 py-2 text-xs disabled:opacity-40"
                            type="button"
                            :disabled="setupLoading || !keywords.trim()"
                            @click="generateDraft"
                        >
                            {{ setupLoading ? '生成中…' : '產生草稿' }}
                        </button>
                    </div>
                </template>

                <!-- Step 1: edit draft -->
                <template v-else-if="setupStep === 1 && setupDraft">
                    <div class="mb-4 space-y-4">
                        <!-- World -->
                        <div>
                            <p class="binary-label mb-1 text-[10px] uppercase text-[var(--binary-outline)]">世界觀</p>
                            <textarea
                                v-model="setupDraft.world"
                                class="binary-input w-full resize-none"
                                rows="3"
                                maxlength="500"
                            />
                        </div>

                        <!-- Opening -->
                        <div>
                            <p class="binary-label mb-1 text-[10px] uppercase text-[var(--binary-outline)]">開場</p>
                            <textarea
                                v-model="setupDraft.opening"
                                class="binary-input w-full resize-none"
                                rows="2"
                                maxlength="300"
                            />
                        </div>

                        <!-- Characters -->
                        <div>
                            <p class="binary-label mb-2 text-[10px] uppercase text-[var(--binary-outline)]">角色</p>
                            <div v-for="(c, i) in setupDraft.characters" :key="i" class="binary-card-raised mb-2 rounded-lg p-3">
                                <input v-model="c.name" class="binary-input mb-2 w-full text-xs" placeholder="角色名稱" maxlength="50" />
                                <textarea v-model="c.persona" class="binary-input mb-1 w-full resize-none text-xs" rows="2" placeholder="個性與動機" maxlength="300" />
                                <input v-model="c.secret" class="binary-input w-full text-xs" placeholder="秘密（可空）" maxlength="200" />
                            </div>
                        </div>

                        <!-- Items -->
                        <div v-if="setupDraft.items?.length">
                            <p class="binary-label mb-2 text-[10px] uppercase text-[var(--binary-outline)]">道具</p>
                            <div v-for="(item, i) in setupDraft.items" :key="i" class="binary-card-raised mb-2 rounded-lg p-3">
                                <input v-model="item.name" class="binary-input mb-1 w-full text-xs" placeholder="道具名稱" maxlength="100" />
                                <input v-model="item.description" class="binary-input w-full text-xs" placeholder="描述" maxlength="300" />
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <button
                            class="binary-ghost-button px-4 py-2 text-xs disabled:opacity-40"
                            type="button"
                            :disabled="setupLoading"
                            @click="refineDraft"
                        >
                            {{ setupLoading ? '優化中…' : '再優化' }}
                        </button>
                        <button
                            class="binary-ghost-button px-4 py-2 text-xs"
                            type="button"
                            @click="setupStep = 2"
                        >
                            確認設定 →
                        </button>
                    </div>
                </template>

                <!-- Step 2: confirm & create -->
                <template v-else-if="setupStep === 2">
                    <div class="mb-4 space-y-4">
                        <div>
                            <p class="binary-label mb-1 text-[10px] uppercase text-[var(--binary-outline)]">故事標題</p>
                            <input v-model="setupTitle" class="binary-input w-full" maxlength="100" placeholder="故事標題" />
                        </div>
                        <div>
                            <p class="binary-label mb-1 text-[10px] uppercase text-[var(--binary-outline)]">推進間隔（分鐘）</p>
                            <input v-model.number="setupInterval" type="number" min="10" max="1440" class="binary-input w-32" />
                        </div>
                        <div>
                            <p class="binary-label mb-2 text-[10px] uppercase text-[var(--binary-outline)]">內容分級</p>
                            <div class="flex gap-3">
                                <label class="flex cursor-pointer items-center gap-2 text-xs text-[var(--binary-text)]">
                                    <input v-model="setupRating" type="radio" value="general" class="accent-[var(--binary-primary)]" />
                                    普通
                                </label>
                                <label class="flex cursor-pointer items-center gap-2 text-xs text-[var(--binary-text-muted)]">
                                    <input v-model="setupRating" type="radio" value="mature" class="accent-[var(--binary-primary)]" />
                                    成人
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="flex gap-3">
                        <button class="binary-ghost-button px-4 py-2 text-xs opacity-60" type="button" @click="setupStep = 1">← 返回</button>
                        <button
                            class="binary-ghost-button px-6 py-2 text-xs disabled:opacity-40"
                            type="button"
                            :disabled="setupLoading || !setupTitle.trim()"
                            @click="createSession"
                        >
                            {{ setupLoading ? '建立中…' : '建立故事' }}
                        </button>
                    </div>
                </template>
            </div>

            <!-- ── Session view ────────────────────────── -->
            <template v-else-if="panel === 'session' && selected">

                <!-- Control bar (admin) -->
                <div v-if="isAdmin" class="flex items-center gap-3 border-b border-[var(--binary-outline)]/20 bg-[var(--binary-surface)] px-5 py-3">
                    <span class="binary-label text-[10px] uppercase" :class="statusColor[selected.status]">
                        {{ statusLabel[selected.status] }}
                    </span>
                    <div class="ml-auto flex gap-2">
                        <button
                            v-if="selected.status === 'paused'"
                            class="binary-ghost-button px-3 py-1 text-[10px] uppercase disabled:opacity-40"
                            :disabled="isUpdatingStatus"
                            type="button"
                            @click="updateStatus('active')"
                        >
                            ▶ 開始
                        </button>
                        <button
                            v-if="selected.status === 'active'"
                            class="binary-ghost-button px-3 py-1 text-[10px] uppercase disabled:opacity-40"
                            :disabled="isUpdatingStatus"
                            type="button"
                            @click="updateStatus('paused')"
                        >
                            ⏸ 暫停
                        </button>
                        <button
                            v-if="selected.status !== 'completed'"
                            class="binary-ghost-button px-3 py-1 text-[10px] uppercase text-red-400/60 hover:text-red-300 disabled:opacity-40"
                            :disabled="isUpdatingStatus"
                            type="button"
                            @click="updateStatus('completed')"
                        >
                            ■ 完結
                        </button>
                    </div>
                </div>

                <!-- Player turn input -->
                <div v-if="isPlayerTurn()" class="border-b border-[var(--binary-outline)]/20 bg-[var(--binary-surface-container)] px-5 py-3">
                    <p class="binary-label mb-2 text-[10px] uppercase text-[var(--binary-primary)]">
                        輪到你了 — {{ selected.current_character?.name }}
                    </p>
                    <textarea
                        v-model="playerTurnContent"
                        class="binary-input mb-2 w-full resize-none"
                        rows="3"
                        placeholder="寫下你的行動或對話…"
                        maxlength="1000"
                    />
                    <div class="flex justify-end">
                        <button
                            class="binary-ghost-button px-4 py-1.5 text-xs disabled:opacity-40"
                            type="button"
                            :disabled="isSubmittingTurn || !playerTurnContent.trim()"
                            @click="submitPlayerTurn"
                        >
                            {{ isSubmittingTurn ? '送出中…' : '送出' }}
                        </button>
                    </div>
                </div>

                <!-- Segments -->
                <div class="flex-1 overflow-y-auto p-5 space-y-4">
                    <p v-if="isLoadingSession" class="text-center text-xs text-[var(--binary-text-muted)]">載入中…</p>

                    <div
                        v-for="seg in selected.segments"
                        :key="seg.id"
                        class="binary-card-raised rounded-xl p-4"
                        :class="seg.is_event ? 'border border-amber-400/20 bg-amber-950/10' : ''"
                    >
                        <div class="mb-2 flex items-center gap-2">
                            <span
                                class="binary-label text-[10px] uppercase"
                                :class="seg.is_event ? 'text-amber-400' : 'text-[var(--binary-outline)]'"
                            >
                                {{ seg.is_event ? '⚡ 外部事件' : (seg.character?.name ?? '旁白') }}
                            </span>
                            <span class="text-[10px] text-[var(--binary-text-muted)]">#{{ seg.turn_number }}</span>
                            <span v-if="seg.is_player_written" class="text-[10px] text-[var(--binary-primary)]">✎ 玩家</span>
                        </div>
                        <p class="whitespace-pre-wrap text-sm leading-relaxed text-[var(--binary-text)]">{{ seg.content }}</p>
                    </div>

                    <p v-if="!isLoadingSession && !selected.segments.length" class="text-center text-xs text-[var(--binary-text-muted)]">
                        故事尚未開始
                    </p>
                </div>
            </template>

        </main>
    </div>
    </AppLayout>
</template>

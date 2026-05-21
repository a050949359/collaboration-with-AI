<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import { useAuth } from '@/composables/useAuth';
import { api } from '@/lib/routes';

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
    characters: { name: string; persona: string; secret?: string; is_narrator?: boolean }[];
    items: { name: string; description: string; holder: string | null }[];
};

type Character = {
    id: number;
    name: string;
    persona: string;
    secret: string | null;
    background: string | null;
    appearance: { age?: string; hair?: string; eyes?: string; build?: string; features?: string } | null;
    outfit: string | null;
    image_prompt: string | null;
    updated_at: string;
};

type CharDraft = {
    name: string;
    persona: string;
    secret: string;
    background: string;
    appearance: { age: string; hair: string; eyes: string; build: string; features: string };
    outfit: string;
    image_prompt: string;
};

// ── Auth ──────────────────────────────────────────────────

const { isAdmin } = useAuth();
const { t } = useI18n();

// ── Main tab ──────────────────────────────────────────────

const mainTab = ref<'story' | 'characters'>('story');

// ── Story state ───────────────────────────────────────────

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
const setupInterval     = ref(30);
const setupRoundsPerAdvance = ref(1);
const setupRating       = ref<'general' | 'mature'>('general');
const setupLoading = ref(false);
const setupError   = ref('');

// Player turn
const playerTurnContent = ref('');
const isSubmittingTurn  = ref(false);

// Control
const isUpdatingStatus = ref(false);

// ── Character state ───────────────────────────────────────

const characters      = ref<Character[]>([]);
const selectedChar    = ref<Character | null>(null);
const charDraft       = ref<CharDraft | null>(null);
const charView        = ref<'idle' | 'generate' | 'edit'>('idle');
const charLoading     = ref(false);
const charSaving      = ref(false);
const charError       = ref('');
const charGenDesc     = ref('');
const charGenGenre    = ref<'fantasy' | 'mystery' | 'scifi' | 'modern'>('fantasy');
const charRefineNotes = ref('');
const charImgLoading  = ref(false);

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

onBeforeUnmount(() => { stopPolling(); stopCountdown(); });

// ── Countdown ─────────────────────────────────────────────

const countdown = ref('');
let countdownTimer: ReturnType<typeof setInterval> | null = null;

function updateCountdown() {
    const ts = selected.value?.next_advance_at;
    if (!ts || selected.value?.status !== 'active') { countdown.value = ''; return; }
    const diff = new Date(ts).getTime() - Date.now();
    if (diff <= 0) { countdown.value = t('story_relay.countdown_imminent'); return; }
    const m = Math.floor(diff / 60000);
    const s = Math.floor((diff % 60000) / 1000);
    countdown.value = `${m}:${s.toString().padStart(2, '0')}`;
}

function startCountdown() {
    stopCountdown();
    updateCountdown();
    countdownTimer = setInterval(updateCountdown, 1000);
}

function stopCountdown() {
    if (countdownTimer !== null) { clearInterval(countdownTimer); countdownTimer = null; }
    countdown.value = '';
}

// ── Info panel state ──────────────────────────────────────

const worldStateExpanded = ref(false);

const worldStateSummary = computed(() => {
    const ws = selected.value?.world_state ?? '';
    return worldStateExpanded.value ? ws : (ws.length > 200 ? ws.slice(0, 200) + '…' : ws);
});

// ── API helpers ───────────────────────────────────────────

async function fetchJson<T>(url: string, init?: RequestInit): Promise<T> {
    const res = await fetch(url, { credentials: 'include', ...init });
    if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error((data as { message?: string }).message ?? `HTTP ${res.status}`);
    }
    if (res.status === 204) return undefined as T;
    return res.json() as Promise<T>;
}

// ── Session list ──────────────────────────────────────────

async function loadSessions() {
    isLoadingList.value = true;
    error.value = '';
    try {
        sessions.value = await fetchJson<SessionListItem[]>(api.story.sessions());
    } catch (e: unknown) {
        error.value = e instanceof Error ? e.message : t('story_relay.err_load');
    } finally {
        isLoadingList.value = false;
    }
}

async function loadSession(id: number, showLoading = true) {
    if (showLoading) isLoadingSession.value = true;
    try {
        selected.value = await fetchJson<StorySession>(api.story.session(id));
        updateCountdown();
    } catch {
        // silent on poll
    } finally {
        isLoadingSession.value = false;
    }
}

async function selectSession(item: SessionListItem) {
    panel.value = 'session';
    setupDraft.value = null;
    worldStateExpanded.value = false;
    await loadSession(item.id);
    startPolling();
    startCountdown();
}

// ── Setup flow ────────────────────────────────────────────

function openSetup() {
    panel.value = 'setup';
    setupStep.value = 0;
    setupDraft.value = null;
    setupTitle.value = '';
    keywords.value = '';
    setupError.value = '';
    setupLoading.value = false;
    setupInterval.value = 30;
    setupRoundsPerAdvance.value = 1;
    setupRating.value = 'general';
    stopPolling();
    stopCountdown();
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
        setupError.value = e instanceof Error ? e.message : t('story_relay.err_generate');
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
        setupError.value = e instanceof Error ? e.message : t('story_relay.err_ai_refine');
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
            rounds_per_advance: setupRoundsPerAdvance.value,
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
        setupError.value = e instanceof Error ? e.message : t('story_relay.err_create');
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
        if (status === 'active') { startPolling(); startCountdown(); }
        else { stopPolling(); stopCountdown(); }
    } catch (e: unknown) {
        error.value = e instanceof Error ? e.message : t('story_relay.err_update');
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
        error.value = e instanceof Error ? e.message : t('story_relay.err_submit');
    } finally {
        isSubmittingTurn.value = false;
    }
}

// ── Character API ─────────────────────────────────────────

function charToDraft(c: Character): CharDraft {
    return {
        name:       c.name,
        persona:    c.persona,
        secret:     c.secret     ?? '',
        background: c.background ?? '',
        appearance: {
            age:      c.appearance?.age      ?? '',
            hair:     c.appearance?.hair     ?? '',
            eyes:     c.appearance?.eyes     ?? '',
            build:    c.appearance?.build    ?? '',
            features: c.appearance?.features ?? '',
        },
        outfit:       c.outfit       ?? '',
        image_prompt: c.image_prompt ?? '',
    };
}

async function loadCharacters() {
    charLoading.value = true;
    charError.value = '';
    try {
        characters.value = await fetchJson<Character[]>(api.characters.list());
    } catch (e: unknown) {
        charError.value = e instanceof Error ? e.message : t('story_relay.err_load');
    } finally {
        charLoading.value = false;
    }
}

function selectChar(c: Character) {
    selectedChar.value = c;
    charDraft.value = charToDraft(c);
    charView.value = 'edit';
    charRefineNotes.value = '';
    charError.value = '';
}

function openGenerate() {
    selectedChar.value = null;
    charDraft.value = null;
    charView.value = 'generate';
    charGenDesc.value = '';
    charError.value = '';
}

async function aiGenerateChar() {
    charLoading.value = true;
    charError.value = '';
    try {
        const res = await fetchJson<{ character: Record<string, unknown> }>(api.characters.aiGenerate(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ description: charGenDesc.value, genre: charGenGenre.value }),
        });
        const saved = await fetchJson<Character>(api.characters.create(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(res.character),
        });
        characters.value.unshift(saved);
        selectChar(saved);
    } catch (e: unknown) {
        charError.value = e instanceof Error ? e.message : t('story_relay.err_ai_generate');
    } finally {
        charLoading.value = false;
    }
}

async function saveChar() {
    if (!selectedChar.value || !charDraft.value) return;
    charSaving.value = true;
    charError.value = '';
    try {
        const updated = await fetchJson<Character>(api.characters.update(selectedChar.value.id), {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(charDraft.value),
        });
        const idx = characters.value.findIndex(c => c.id === updated.id);
        if (idx !== -1) characters.value[idx] = updated;
        selectedChar.value = updated;
        charDraft.value = charToDraft(updated);
    } catch (e: unknown) {
        charError.value = e instanceof Error ? e.message : t('story_relay.err_save');
    } finally {
        charSaving.value = false;
    }
}

async function aiRefineChar() {
    if (!selectedChar.value || !charDraft.value) return;
    charLoading.value = true;
    charError.value = '';
    try {
        const res = await fetchJson<{ character: Record<string, unknown> }>(api.characters.aiRefine(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ character: charDraft.value, notes: charRefineNotes.value }),
        });
        const c = res.character;
        const app = (c.appearance as CharDraft['appearance'] | null) ?? charDraft.value.appearance;
        charDraft.value = {
            name:       String(c.name       ?? charDraft.value.name),
            persona:    String(c.persona    ?? charDraft.value.persona),
            secret:     String(c.secret     ?? ''),
            background: String(c.background ?? ''),
            appearance: {
                age:      String(app?.age      ?? ''),
                hair:     String(app?.hair     ?? ''),
                eyes:     String(app?.eyes     ?? ''),
                build:    String(app?.build    ?? ''),
                features: String(app?.features ?? ''),
            },
            outfit:       String(c.outfit       ?? ''),
            image_prompt: charDraft.value.image_prompt,
        };
        charRefineNotes.value = '';
    } catch (e: unknown) {
        charError.value = e instanceof Error ? e.message : t('story_relay.err_ai_refine');
    } finally {
        charLoading.value = false;
    }
}

async function generateImagePrompt() {
    if (!selectedChar.value) return;
    charImgLoading.value = true;
    charError.value = '';
    try {
        const res = await fetchJson<{ image_prompt: string }>(api.characters.imagePrompt(selectedChar.value.id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ character: charDraft.value }),
        });
        if (charDraft.value) charDraft.value.image_prompt = res.image_prompt;
        selectedChar.value = { ...selectedChar.value, image_prompt: res.image_prompt };
        const idx = characters.value.findIndex(c => c.id === selectedChar.value!.id);
        if (idx !== -1) characters.value[idx] = selectedChar.value;
    } catch (e: unknown) {
        charError.value = e instanceof Error ? e.message : t('story_relay.err_generate');
    } finally {
        charImgLoading.value = false;
    }
}

async function deleteChar() {
    if (!selectedChar.value) return;
    if (!confirm(t('story_relay.confirm_delete', { name: selectedChar.value.name }))) return;
    charLoading.value = true;
    try {
        await fetchJson(api.characters.destroy(selectedChar.value.id), { method: 'DELETE' });
        characters.value = characters.value.filter(c => c.id !== selectedChar.value!.id);
        selectedChar.value = null;
        charDraft.value = null;
        charView.value = 'idle';
    } catch (e: unknown) {
        charError.value = e instanceof Error ? e.message : t('story_relay.err_delete');
    } finally {
        charLoading.value = false;
    }
}

// ── Helpers ───────────────────────────────────────────────

const statusLabel = computed<Record<string, string>>(() => ({
    active:    t('story_relay.status_active'),
    paused:    t('story_relay.status_paused'),
    completed: t('story_relay.status_completed'),
}));
const statusColor: Record<string, string> = {
    active:    'text-emerald-400',
    paused:    'text-yellow-400',
    completed: 'text-[var(--binary-text-muted)]',
};

const genreOptions = computed(() => [
    { value: 'fantasy', label: t('story_relay.genre_fantasy') },
    { value: 'mystery', label: t('story_relay.genre_mystery') },
    { value: 'scifi',   label: t('story_relay.genre_scifi') },
    { value: 'modern',  label: t('story_relay.genre_modern') },
]);

function isPlayerTurn(): boolean {
    return (
        !!selected.value &&
        selected.value.status === 'active' &&
        selected.value.current_character?.type === 'player'
    );
}

onMounted(() => { loadSessions(); loadCharacters(); });
</script>

<template>
    <AppLayout>
    <Head :title="t('story_relay.head_title')" />

    <div class="mx-auto mt-24 flex h-[calc(100vh-6rem)] w-full max-w-screen-2xl flex-col overflow-hidden px-6 font-mono md:px-8">

        <!-- ── Tab bar ───────────────────────────────────── -->
        <div class="flex shrink-0 border-b border-[var(--binary-outline)]/20">
            <button
                v-for="tab in [{ key: 'story', label: t('story_relay.tab_story') }, { key: 'characters', label: t('story_relay.tab_characters') }]"
                :key="tab.key"
                type="button"
                class="px-5 py-2.5 text-xs uppercase tracking-widest transition"
                :class="mainTab === tab.key
                    ? 'border-b-2 border-[var(--binary-primary)] text-[var(--binary-primary)]'
                    : 'text-[var(--binary-text-muted)] hover:text-[var(--binary-text)]'"
                @click="mainTab = tab.key as typeof mainTab"
            >
                {{ tab.label }}
            </button>
        </div>

        <!-- ── Story tab ─────────────────────────────────── -->
        <div v-if="mainTab === 'story'" class="flex flex-1 overflow-hidden">

            <!-- Left panel: session list -->
            <aside class="flex w-3/12 flex-col border-r border-[var(--binary-outline)]/20 bg-[var(--binary-surface)]">
                <div class="border-b border-[var(--binary-outline)]/20 p-4">
                    <button
                        class="binary-ghost-button w-full py-2 text-xs uppercase tracking-widest"
                        type="button"
                        @click="openSetup"
                    >
                        {{ t('story_relay.new_story') }}
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto p-2">
                    <p v-if="isLoadingList" class="p-4 text-center text-xs text-[var(--binary-text-muted)]">{{ t('story_relay.loading') }}</p>
                    <p v-else-if="!sessions.length" class="p-4 text-center text-xs text-[var(--binary-text-muted)]">{{ t('story_relay.no_stories') }}</p>
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

            <!-- Right panel -->
            <main class="flex w-9/12 flex-col overflow-hidden">

                <!-- Empty state -->
                <div v-if="panel === 'none'" class="flex flex-1 items-center justify-center">
                    <p class="binary-label text-xs uppercase text-[var(--binary-text-muted)]">{{ t('story_relay.empty_select') }}</p>
                </div>

                <!-- Setup area -->
                <div v-else-if="panel === 'setup'" class="flex flex-1 flex-col overflow-y-auto p-6">
                    <h2 class="binary-label mb-6 text-xs uppercase tracking-widest text-[var(--binary-outline)]">{{ t('story_relay.setup_title') }}</h2>

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
                            :placeholder="t('story_relay.setup_keywords_placeholder')"
                            maxlength="200"
                        />
                        <div class="flex justify-end">
                            <button
                                class="binary-ghost-button px-6 py-2 text-xs disabled:opacity-40"
                                type="button"
                                :disabled="setupLoading || !keywords.trim()"
                                @click="generateDraft"
                            >
                                {{ setupLoading ? t('story_relay.setup_generating') : t('story_relay.setup_generate') }}
                            </button>
                        </div>
                    </template>

                    <!-- Step 1: edit draft -->
                    <template v-else-if="setupStep === 1 && setupDraft">
                        <div class="mb-4 space-y-4">
                            <div>
                                <p class="binary-label mb-1 text-[10px] uppercase text-[var(--binary-outline)]">{{ t('story_relay.setup_world') }}</p>
                                <textarea v-model="setupDraft.world" class="binary-input w-full resize-none" rows="3" maxlength="500" />
                            </div>
                            <div>
                                <p class="binary-label mb-1 text-[10px] uppercase text-[var(--binary-outline)]">{{ t('story_relay.setup_opening') }}</p>
                                <textarea v-model="setupDraft.opening" class="binary-input w-full resize-none" rows="2" maxlength="300" />
                            </div>
                            <div>
                                <p class="binary-label mb-2 text-[10px] uppercase text-[var(--binary-outline)]">{{ t('story_relay.setup_characters_label') }}</p>
                                <div v-for="(c, i) in setupDraft.characters" :key="i" class="binary-card-raised mb-2 rounded-lg p-3">
                                    <div class="mb-2 flex items-center justify-between gap-2">
                                        <input v-model="c.name" class="binary-input flex-1 text-xs" :placeholder="t('story_relay.setup_char_name')" maxlength="50" />
                                        <button
                                            type="button"
                                            class="shrink-0 rounded px-2 py-1 text-[10px] uppercase tracking-widest transition"
                                            :class="c.is_narrator !== false
                                                ? 'bg-[var(--binary-primary)]/15 text-[var(--binary-primary)]'
                                                : 'text-[var(--binary-text-muted)] hover:text-[var(--binary-text)]'"
                                            @click="c.is_narrator = c.is_narrator === false ? true : false"
                                        >
                                            {{ c.is_narrator !== false ? t('story_relay.btn_narrator') : t('story_relay.btn_observer') }}
                                        </button>
                                    </div>
                                    <textarea v-model="c.persona" class="binary-input mb-1 w-full resize-none text-xs" rows="2" :placeholder="t('story_relay.setup_char_persona')" maxlength="300" />
                                    <input v-model="c.secret" class="binary-input w-full text-xs" :placeholder="t('story_relay.setup_char_secret')" maxlength="200" />
                                </div>
                            </div>
                            <div v-if="setupDraft.items?.length">
                                <p class="binary-label mb-2 text-[10px] uppercase text-[var(--binary-outline)]">{{ t('story_relay.setup_items_label') }}</p>
                                <div v-for="(item, i) in setupDraft.items" :key="i" class="binary-card-raised mb-2 rounded-lg p-3">
                                    <input v-model="item.name" class="binary-input mb-1 w-full text-xs" :placeholder="t('story_relay.setup_item_name')" maxlength="100" />
                                    <input v-model="item.description" class="binary-input w-full text-xs" :placeholder="t('story_relay.setup_item_desc')" maxlength="300" />
                                </div>
                            </div>
                        </div>
                        <div class="flex gap-3">
                            <button class="binary-ghost-button px-4 py-2 text-xs disabled:opacity-40" type="button" :disabled="setupLoading" @click="refineDraft">
                                {{ setupLoading ? t('story_relay.btn_refining') : t('story_relay.btn_refine') }}
                            </button>
                            <button class="binary-ghost-button px-4 py-2 text-xs" type="button" @click="setupStep = 2">{{ t('story_relay.btn_confirm_setup') }}</button>
                        </div>
                    </template>

                    <!-- Step 2: confirm & create -->
                    <template v-else-if="setupStep === 2">
                        <div class="mb-4 space-y-4">
                            <div>
                                <p class="binary-label mb-1 text-[10px] uppercase text-[var(--binary-outline)]">{{ t('story_relay.setup_story_title') }}</p>
                                <input v-model="setupTitle" class="binary-input w-full" maxlength="100" :placeholder="t('story_relay.setup_title_placeholder')" />
                            </div>
                            <div class="flex gap-6">
                                <div>
                                    <p class="binary-label mb-1 text-[10px] uppercase text-[var(--binary-outline)]">{{ t('story_relay.setup_interval') }}</p>
                                    <input v-model.number="setupInterval" type="number" min="10" max="1440" class="binary-input w-28" />
                                </div>
                                <div>
                                    <p class="binary-label mb-1 text-[10px] uppercase text-[var(--binary-outline)]">{{ t('story_relay.setup_rounds') }}</p>
                                    <input v-model.number="setupRoundsPerAdvance" type="number" min="1" max="10" class="binary-input w-20" />
                                </div>
                            </div>
                            <div>
                                <p class="binary-label mb-2 text-[10px] uppercase text-[var(--binary-outline)]">{{ t('story_relay.setup_rating') }}</p>
                                <div class="flex gap-3">
                                    <label class="flex cursor-pointer items-center gap-2 text-xs text-[var(--binary-text)]">
                                        <input v-model="setupRating" type="radio" value="general" class="accent-[var(--binary-primary)]" /> {{ t('story_relay.rating_general') }}
                                    </label>
                                    <label class="flex cursor-pointer items-center gap-2 text-xs text-[var(--binary-text-muted)]">
                                        <input v-model="setupRating" type="radio" value="mature" class="accent-[var(--binary-primary)]" /> {{ t('story_relay.rating_mature') }}
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="flex gap-3">
                            <button class="binary-ghost-button px-4 py-2 text-xs opacity-60" type="button" @click="setupStep = 1">{{ t('story_relay.btn_back') }}</button>
                            <button
                                class="binary-ghost-button px-6 py-2 text-xs disabled:opacity-40"
                                type="button"
                                :disabled="setupLoading || !setupTitle.trim()"
                                @click="createSession"
                            >
                                {{ setupLoading ? t('story_relay.btn_creating') : t('story_relay.btn_create') }}
                            </button>
                        </div>
                    </template>
                </div>

                <!-- Session view -->
                <template v-else-if="panel === 'session' && selected">

                    <!-- Control bar (admin) -->
                    <div v-if="isAdmin" class="flex items-center gap-3 border-b border-[var(--binary-outline)]/20 bg-[var(--binary-surface)] px-5 py-3">
                        <span class="binary-label text-[10px] uppercase" :class="statusColor[selected.status]">
                            {{ statusLabel[selected.status] }}
                        </span>
                        <div class="ml-auto flex gap-2">
                            <button v-if="selected.status === 'paused'" class="binary-ghost-button px-3 py-1 text-[10px] uppercase disabled:opacity-40" :disabled="isUpdatingStatus" type="button" @click="updateStatus('active')">{{ t('story_relay.btn_start') }}</button>
                            <button v-if="selected.status === 'active'" class="binary-ghost-button px-3 py-1 text-[10px] uppercase disabled:opacity-40" :disabled="isUpdatingStatus" type="button" @click="updateStatus('paused')">{{ t('story_relay.btn_pause') }}</button>
                            <button v-if="selected.status !== 'completed'" class="binary-ghost-button px-3 py-1 text-[10px] uppercase text-red-400/60 hover:text-red-300 disabled:opacity-40" :disabled="isUpdatingStatus" type="button" @click="updateStatus('completed')">{{ t('story_relay.btn_end') }}</button>
                        </div>
                    </div>

                    <!-- Player turn input -->
                    <div v-if="isPlayerTurn()" class="border-b border-[var(--binary-outline)]/20 bg-[var(--binary-surface-container)] px-5 py-3">
                        <p class="binary-label mb-2 text-[10px] uppercase text-[var(--binary-primary)]">{{ t('story_relay.player_turn', { name: selected.current_character?.name }) }}</p>
                        <textarea v-model="playerTurnContent" class="binary-input mb-2 w-full resize-none" rows="3" :placeholder="t('story_relay.turn_placeholder')" maxlength="1000" />
                        <div class="flex justify-end">
                            <button class="binary-ghost-button px-4 py-1.5 text-xs disabled:opacity-40" type="button" :disabled="isSubmittingTurn || !playerTurnContent.trim()" @click="submitPlayerTurn">
                                {{ isSubmittingTurn ? t('story_relay.btn_submitting') : t('story_relay.btn_submit') }}
                            </button>
                        </div>
                    </div>

                    <!-- Content row: segments + info sidebar -->
                    <div class="flex flex-1 overflow-hidden">

                        <!-- Segments -->
                        <div class="flex-1 overflow-y-auto p-5 space-y-4">
                            <p v-if="isLoadingSession" class="text-center text-xs text-[var(--binary-text-muted)]">{{ t('story_relay.loading') }}</p>
                            <div
                                v-for="seg in selected.segments"
                                :key="seg.id"
                                class="binary-card-raised rounded-xl p-4"
                                :class="seg.is_event ? 'border border-amber-400/20 bg-amber-950/10' : ''"
                            >
                                <div class="mb-2 flex items-center gap-2">
                                    <span class="binary-label text-[10px] uppercase" :class="seg.is_event ? 'text-amber-400' : 'text-[var(--binary-outline)]'">
                                        {{ seg.is_event ? t('story_relay.event_label') : (seg.character?.name ?? t('story_relay.narrator_label')) }}
                                    </span>
                                    <span class="text-[10px] text-[var(--binary-text-muted)]">#{{ seg.turn_number }}</span>
                                    <span v-if="seg.is_player_written" class="text-[10px] text-[var(--binary-primary)]">{{ t('story_relay.player_badge') }}</span>
                                </div>
                                <p class="whitespace-pre-wrap text-sm leading-relaxed text-[var(--binary-text)]">{{ seg.content }}</p>
                            </div>
                            <p v-if="!isLoadingSession && !selected.segments.length" class="text-center text-xs text-[var(--binary-text-muted)]">{{ t('story_relay.no_segments') }}</p>
                        </div>

                        <!-- Info sidebar -->
                        <aside class="flex w-56 shrink-0 flex-col gap-5 overflow-y-auto border-l border-[var(--binary-outline)]/20 bg-[var(--binary-surface)] p-4">
                            <div>
                                <p class="binary-label mb-1 text-[10px] uppercase text-[var(--binary-outline)]">{{ t('story_relay.label_next_advance') }}</p>
                                <p class="text-xs" :class="countdown ? 'text-[var(--binary-primary)]' : 'text-[var(--binary-text-muted)]'">
                                    {{ countdown || (selected.status === 'active' ? t('story_relay.countdown_calculating') : t('story_relay.countdown_paused')) }}
                                </p>
                            </div>
                            <div>
                                <p class="binary-label mb-2 text-[10px] uppercase text-[var(--binary-outline)]">{{ t('story_relay.label_characters_sidebar') }}</p>
                                <div class="space-y-1.5">
                                    <div
                                        v-for="c in selected.characters"
                                        :key="c.id"
                                        class="flex items-start gap-2 rounded-lg px-2 py-1.5 text-xs transition"
                                        :class="c.id === selected.current_character_id && selected.status === 'active'
                                            ? 'bg-[var(--binary-primary)]/10 text-[var(--binary-primary)]'
                                            : 'text-[var(--binary-text-muted)]'"
                                    >
                                        <span class="mt-px shrink-0 text-[10px]">{{ c.id === selected.current_character_id && selected.status === 'active' ? '▶' : '·' }}</span>
                                        <div class="min-w-0">
                                            <p class="truncate font-semibold leading-tight">{{ c.name }}</p>
                                            <p v-if="c.status !== 'active'" class="text-[10px] text-red-400/70">{{ c.status }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div v-if="selected.items.length">
                                <p class="binary-label mb-2 text-[10px] uppercase text-[var(--binary-outline)]">{{ t('story_relay.label_items_sidebar') }}</p>
                                <div class="space-y-1.5">
                                    <div v-for="item in selected.items" :key="item.id" class="text-xs text-[var(--binary-text-muted)]">
                                        <p class="font-semibold text-[var(--binary-text)]">{{ item.name }}</p>
                                        <p class="text-[10px]">{{ item.holder?.name ?? t('story_relay.no_holder') }}</p>
                                    </div>
                                </div>
                            </div>
                            <div v-if="selected.world_state">
                                <p class="binary-label mb-2 text-[10px] uppercase text-[var(--binary-outline)]">{{ t('story_relay.label_world_state') }}</p>
                                <p class="whitespace-pre-wrap text-[10px] leading-relaxed text-[var(--binary-text-muted)]">{{ worldStateSummary }}</p>
                                <button v-if="selected.world_state.length > 200" class="mt-1 text-[10px] text-[var(--binary-outline)] hover:text-[var(--binary-text)]" type="button" @click="worldStateExpanded = !worldStateExpanded">
                                    {{ worldStateExpanded ? t('story_relay.btn_collapse') : t('story_relay.btn_expand') }}
                                </button>
                            </div>
                        </aside>
                    </div>
                </template>

            </main>
        </div>

        <!-- ── Characters tab ────────────────────────────── -->
        <div v-else class="flex flex-1 overflow-hidden">

            <!-- Left: character list -->
            <aside class="flex w-72 shrink-0 flex-col border-r border-[var(--binary-outline)]/20 bg-[var(--binary-surface)]">
                <div class="border-b border-[var(--binary-outline)]/20 p-4">
                    <button class="binary-ghost-button w-full py-2 text-xs uppercase tracking-widest" type="button" @click="openGenerate">
                        {{ t('story_relay.new_char') }}
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto p-2">
                    <p v-if="charLoading && !characters.length" class="p-4 text-center text-xs text-[var(--binary-text-muted)]">{{ t('story_relay.loading') }}</p>
                    <p v-else-if="!characters.length" class="p-4 text-center text-xs text-[var(--binary-text-muted)]">{{ t('story_relay.no_chars') }}</p>
                    <button
                        v-for="c in characters"
                        :key="c.id"
                        type="button"
                        class="mb-1 w-full rounded-lg px-3 py-2.5 text-left transition"
                        :class="selectedChar?.id === c.id
                            ? 'bg-[var(--binary-outline)]/10 text-[var(--binary-text)]'
                            : 'text-[var(--binary-text-muted)] hover:bg-[var(--binary-surface-container)]'"
                        @click="selectChar(c)"
                    >
                        <p class="truncate text-xs font-semibold">{{ c.name }}</p>
                        <p class="mt-0.5 truncate text-[10px] text-[var(--binary-text-muted)]">{{ c.persona.slice(0, 60) }}{{ c.persona.length > 60 ? '…' : '' }}</p>
                    </button>
                </div>
            </aside>

            <!-- Right: editor -->
            <div class="flex flex-1 flex-col overflow-y-auto">

                <!-- Idle -->
                <div v-if="charView === 'idle'" class="flex flex-1 items-center justify-center p-6">
                    <p class="binary-label text-xs uppercase text-[var(--binary-text-muted)]">{{ t('story_relay.empty_char_select') }}</p>
                </div>

                <!-- Generate -->
                <div v-else-if="charView === 'generate'" class="p-6">
                    <h2 class="binary-label mb-6 text-xs uppercase tracking-widest text-[var(--binary-outline)]">{{ t('story_relay.char_generate_title') }}</h2>
                    <p v-if="charError" class="mb-4 rounded-lg border border-red-400/20 bg-red-950/20 px-4 py-2 text-xs text-red-300">{{ charError }}</p>
                    <div class="mb-4 grid grid-cols-4 gap-2">
                        <button
                            v-for="g in genreOptions"
                            :key="g.value"
                            type="button"
                            class="rounded-lg border px-3 py-2 text-xs transition"
                            :class="charGenGenre === g.value
                                ? 'border-[var(--binary-primary)] text-[var(--binary-primary)]'
                                : 'border-[var(--binary-outline)]/30 text-[var(--binary-text-muted)] hover:border-[var(--binary-outline)]'"
                            @click="charGenGenre = g.value as typeof charGenGenre"
                        >{{ g.label }}</button>
                    </div>
                    <textarea
                        v-model="charGenDesc"
                        class="binary-input mb-4 w-full resize-none"
                        rows="4"
                        :placeholder="t('story_relay.char_desc_placeholder')"
                        maxlength="500"
                    />
                    <div class="flex justify-end">
                        <button class="binary-ghost-button px-6 py-2 text-xs disabled:opacity-40" type="button" :disabled="charLoading" @click="aiGenerateChar">
                            {{ charLoading ? t('story_relay.setup_generating') : t('story_relay.btn_ai_generate') }}
                        </button>
                    </div>
                </div>

                <!-- Edit -->
                <div v-else-if="charView === 'edit' && charDraft" class="p-6">
                    <div class="mb-5 flex items-center justify-between">
                        <h2 class="binary-label text-xs uppercase tracking-widest text-[var(--binary-outline)]">{{ t('story_relay.char_edit_title') }}</h2>
                        <button class="text-[10px] text-red-400/60 transition hover:text-red-300 disabled:opacity-40" type="button" :disabled="charLoading" @click="deleteChar">{{ t('story_relay.btn_delete') }}</button>
                    </div>

                    <p v-if="charError" class="mb-4 rounded-lg border border-red-400/20 bg-red-950/20 px-4 py-2 text-xs text-red-300">{{ charError }}</p>

                    <div class="space-y-4">
                        <div>
                            <p class="binary-label mb-1 text-[10px] uppercase text-[var(--binary-outline)]">{{ t('story_relay.label_name') }}</p>
                            <input v-model="charDraft.name" class="binary-input w-full" maxlength="100" />
                        </div>
                        <div>
                            <p class="binary-label mb-1 text-[10px] uppercase text-[var(--binary-outline)]">{{ t('story_relay.label_persona') }}</p>
                            <textarea v-model="charDraft.persona" class="binary-input w-full resize-none" rows="3" maxlength="500" />
                        </div>
                        <div>
                            <p class="binary-label mb-1 text-[10px] uppercase text-[var(--binary-outline)]">{{ t('story_relay.label_background') }}</p>
                            <textarea v-model="charDraft.background" class="binary-input w-full resize-none" rows="2" maxlength="500" />
                        </div>
                        <div>
                            <p class="binary-label mb-1 text-[10px] uppercase text-[var(--binary-outline)]">{{ t('story_relay.label_secret') }}</p>
                            <textarea v-model="charDraft.secret" class="binary-input w-full resize-none" rows="2" maxlength="500" />
                        </div>
                        <div>
                            <p class="binary-label mb-2 text-[10px] uppercase text-[var(--binary-outline)]">{{ t('story_relay.label_appearance') }}</p>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <p class="mb-1 text-[10px] text-[var(--binary-text-muted)]">{{ t('story_relay.appearance_age') }}</p>
                                    <input v-model="charDraft.appearance.age" class="binary-input w-full text-xs" maxlength="50" />
                                </div>
                                <div>
                                    <p class="mb-1 text-[10px] text-[var(--binary-text-muted)]">{{ t('story_relay.appearance_hair') }}</p>
                                    <input v-model="charDraft.appearance.hair" class="binary-input w-full text-xs" maxlength="100" />
                                </div>
                                <div>
                                    <p class="mb-1 text-[10px] text-[var(--binary-text-muted)]">{{ t('story_relay.appearance_eyes') }}</p>
                                    <input v-model="charDraft.appearance.eyes" class="binary-input w-full text-xs" maxlength="100" />
                                </div>
                                <div>
                                    <p class="mb-1 text-[10px] text-[var(--binary-text-muted)]">{{ t('story_relay.appearance_build') }}</p>
                                    <input v-model="charDraft.appearance.build" class="binary-input w-full text-xs" maxlength="100" />
                                </div>
                                <div class="col-span-2">
                                    <p class="mb-1 text-[10px] text-[var(--binary-text-muted)]">{{ t('story_relay.appearance_features') }}</p>
                                    <input v-model="charDraft.appearance.features" class="binary-input w-full text-xs" maxlength="200" />
                                </div>
                            </div>
                        </div>
                        <div>
                            <p class="binary-label mb-1 text-[10px] uppercase text-[var(--binary-outline)]">{{ t('story_relay.label_outfit') }}</p>
                            <input v-model="charDraft.outfit" class="binary-input w-full" maxlength="300" />
                        </div>
                        <div>
                            <p class="binary-label mb-1 text-[10px] uppercase text-[var(--binary-outline)]">{{ t('story_relay.label_image_prompt') }}</p>
                            <textarea v-model="charDraft.image_prompt" class="binary-input w-full resize-none text-[10px] text-[var(--binary-text-muted)]" rows="3" />
                            <button
                                class="binary-ghost-button mt-2 px-4 py-1.5 text-xs disabled:opacity-40"
                                type="button"
                                :disabled="charImgLoading"
                                @click="generateImagePrompt"
                            >
                                {{ charImgLoading ? t('story_relay.btn_img_generating') : t('story_relay.btn_ai_image_prompt') }}
                            </button>
                        </div>

                        <!-- AI refine -->
                        <div class="rounded-lg border border-[var(--binary-outline)]/20 p-3">
                            <p class="binary-label mb-2 text-[10px] uppercase text-[var(--binary-outline)]">{{ t('story_relay.label_ai_refine') }}</p>
                            <input v-model="charRefineNotes" class="binary-input mb-2 w-full text-xs" :placeholder="t('story_relay.refine_placeholder')" maxlength="300" />
                            <button class="binary-ghost-button px-4 py-1.5 text-[10px] disabled:opacity-40" type="button" :disabled="charLoading" @click="aiRefineChar">
                                {{ charLoading ? t('story_relay.btn_refining') : t('story_relay.btn_ai_refine_char') }}
                            </button>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <button class="binary-ghost-button px-6 py-2 text-xs disabled:opacity-40" type="button" :disabled="charSaving" @click="saveChar">
                            {{ charSaving ? t('story_relay.btn_saving') : t('story_relay.btn_save') }}
                        </button>
                    </div>
                </div>

            </div>
        </div>

    </div>
    </AppLayout>
</template>

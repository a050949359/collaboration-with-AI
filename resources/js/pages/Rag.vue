<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

import { useAuth } from '../composables/useAuth';
import AppLayout from '../layouts/AppLayout.vue';
import { api } from '../lib/routes';

const { user } = useAuth();

interface DriveFile {
    id: string;
    name: string;
    mime_type: string;
    modified_time: string;
    status?: string;
    document_id?: number | null;
}
interface Kb {
    id: number;
    name: string;
    collection: string;
    committed_count: number;
    draft_count: number;
    file_status?: string | null; // 選了檔時：此檔在該庫的狀態 new/draft/committed/dirty
}
interface Chunk {
    index: number;
    content: string;
    context: string | null;
    chars: number;
    embedded: boolean;
}
interface Diff {
    unchanged: number;
    added: number;
    removed: number;
}
interface NearDup {
    a: number;
    b: number;
    similarity: number;
}
interface TestHit {
    chunk_index: number;
    content: string;
    similarity: number;
}
interface DashDoc {
    drive_file_id: string;
    name: string;
    status: string;
    chunk_count: number;
    committed_at: string | null;
}
interface DashKb {
    id: number;
    name: string;
    collection: string;
    embedding_model: string;
    dimensions: number;
    vector_count: number;
    documents: DashDoc[];
}

interface QaSource {
    content: string;
    similarity?: number;
    metadata?: { file_name?: string; chunk_index?: number };
}

type Step = 'files' | 'kb' | 'editor';
type View = 'dashboard' | 'wizard' | 'qa';

const view = ref<View>('dashboard');
const step = ref<Step>('files');
const error = ref<string | null>(null);
const busy = ref(false);

// dashboard
const dash = ref<DashKb[]>([]);
const dashTab = ref<'by_kb' | 'by_file'>('by_kb');

// qa（問答）
const qaKbId = ref<number | null>(null);
const qaQuery = ref('');
const qaAnswer = ref<string | null>(null);
const qaSources = ref<QaSource[]>([]);

// step 1
const driveFiles = ref<DriveFile[]>([]);
const selectedFile = ref<DriveFile | null>(null);

// step 2
const kbs = ref<Kb[]>([]);
const selectedKb = ref<Kb | null>(null);
const newKbName = ref('');
const manageMode = ref(false);
const selectedKbIds = ref<number[]>([]);

// step 3
const documentId = ref<number | null>(null);
const chunks = ref<Chunk[]>([]);
const diff = ref<Diff | null>(null);
const lockToken = ref<string | null>(null);
const lockedByOther = ref(false);
const nearDups = ref<NearDup[]>([]);
const testQuery = ref('');
const testResults = ref<TestHit[]>([]);
const showCommitModal = ref(false);
const committedInfo = ref<string | null>(null);
// 每塊上次已存的 content/context（index → 值），blur 自動存時用來判斷是否有變
const saved = ref<Record<number, { content: string; context: string | null }>>(
    {},
);
// 目前游標所在塊與位置（決定併上/併下是「移半邊」還是「整塊」、以及能否分割）
const caret = ref<{ index: number; pos: number } | null>(null);

const canEdit = computed(() => !!lockToken.value && !lockedByOther.value);

// ── http helpers ──────────────────────────────────────────
async function getJSON(url: string) {
    const res = await fetch(url, {
        credentials: 'include',
        headers: { Accept: 'application/json' },
    });

    if (!res.ok) {
        throw new Error(`HTTP ${res.status}`);
    }

    return res.json();
}
async function sendJSON(url: string, method: string, body?: unknown) {
    const res = await fetch(url, {
        method,
        credentials: 'include',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    const json = await res.json().catch(() => ({}));

    if (!res.ok) {
        throw new Error(json.message || json.error || `HTTP ${res.status}`);
    }

    return json;
}

function run(fn: () => Promise<void>) {
    busy.value = true;
    error.value = null;
    fn()
        .catch(
            (e) => (error.value = e instanceof Error ? e.message : String(e)),
        )
        .finally(() => (busy.value = false));
}

// ── step 1: files ─────────────────────────────────────────
function loadFiles() {
    run(async () => {
        driveFiles.value = (await getJSON(api.rag.driveIndex())).data;
    });
}
function loadKbs(driveFileId?: string) {
    run(async () => {
        kbs.value = (await getJSON(api.rag.kbs(driveFileId))).data;
    });
}
function pickFile(f: DriveFile) {
    selectedFile.value = f;
    step.value = 'kb';
    // 帶檔 id 重載：每個庫會標出此檔的狀態（未加入/草稿/已落庫/待更新）
    loadKbs(f.id);
}
function fileStatusLabel(s?: string | null): string {
    return (
        {
            draft: '📝 此檔有草稿',
            dirty: '⚠ 此檔草稿待更新',
            committed: '✓ 此檔已落庫',
        }[s ?? ''] ?? ''
    );
}

// ── step 2: knowledge base ────────────────────────────────
function createKb() {
    const name = newKbName.value.trim();

    if (!name) {
        return;
    }

    run(async () => {
        const r = await sendJSON(api.rag.kbs(), 'POST', { name });
        newKbName.value = '';
        kbs.value = (await getJSON(api.rag.kbs(selectedFile.value?.id))).data;
        const created = kbs.value.find((k) => k.id === r.id);

        if (created) {
            chooseKb(created);
        }
    });
}
function chooseKb(kb: Kb) {
    selectedKb.value = kb;
    run(async () => {
        const r = await sendJSON(api.rag.documents(kb.id), 'POST', {
            drive_file_id: selectedFile.value!.id,
        });
        documentId.value = r.document_id;
        diff.value = r.diff;
        committedInfo.value = r.loaded
            ? '已載入此檔既有草稿（未覆蓋）。若 Drive 上已更新，可按「重新切塊」。'
            : null;
        await loadChunks();
        step.value = 'editor';
    });
}

// 強制從 Drive 重新抽取重切（會覆蓋現有草稿編輯）—— 需使用者確認
function reproposeForce() {
    if (
        !selectedKb.value ||
        !selectedFile.value ||
        !confirm(
            '重新切塊會從 Drive 重抽並覆蓋現有草稿（含手填 context），確定？',
        )
    ) {
        return;
    }

    run(async () => {
        const r = await sendJSON(
            api.rag.documents(selectedKb.value!.id),
            'POST',
            {
                drive_file_id: selectedFile.value!.id,
                force: true,
            },
        );
        documentId.value = r.document_id;
        diff.value = r.diff;
        committedInfo.value = `已重新切塊：未變 ${r.diff.unchanged} / 新增 ${r.diff.added} / 刪除 ${r.diff.removed}`;
        await loadChunks();
    });
}

function toggleManage() {
    manageMode.value = !manageMode.value;
    selectedKbIds.value = [];
}
function toggleKbSelect(id: number) {
    const i = selectedKbIds.value.indexOf(id);

    if (i >= 0) {
        selectedKbIds.value.splice(i, 1);
    } else {
        selectedKbIds.value.push(id);
    }
}
function deleteSelectedKbs() {
    const ids = [...selectedKbIds.value];

    if (
        !ids.length ||
        !confirm(
            `刪除 ${ids.length} 個知識庫？連同其文件/草稿/向量一併清除，不可復原。`,
        )
    ) {
        return;
    }

    run(async () => {
        for (const id of ids) {
            await sendJSON(api.rag.kb(id), 'DELETE');
        }

        selectedKbIds.value = [];
        manageMode.value = false;
        kbs.value = (await getJSON(api.rag.kbs(selectedFile.value?.id))).data;
    });
}

// ── step 3: editor ────────────────────────────────────────
function snapshotChunks() {
    saved.value = {};

    for (const c of chunks.value) {
        saved.value[c.index] = { content: c.content, context: c.context };
    }
}
async function loadChunks() {
    const r = await getJSON(api.rag.chunks(documentId.value!));
    chunks.value = r.chunks;
    lockedByOther.value = !!r.lock && r.lock.locked_by !== user.value?.id;
    caret.value = null;
    snapshotChunks();
}
function refreshDraft() {
    // 重新載入草稿（取得 Claude/他處經 MCP 的最新修改）。
    // blur 自動存會在點按鈕前先把正在編的存起來，不會遺失。
    run(async () => {
        await loadChunks();
    });
}
function acquireLock() {
    run(async () => {
        const r = await sendJSON(api.rag.lock(documentId.value!), 'POST');
        lockToken.value = r.lock_token;
        lockedByOther.value = false;
    });
}
function copyToken() {
    if (!lockToken.value) {
        return;
    }

    navigator.clipboard?.writeText(lockToken.value);
    committedInfo.value =
        '已複製 lock token，可貼給 Claude（rag_resume）接手編輯。';
}
function releaseLock() {
    if (!lockToken.value) {
        return;
    }

    run(async () => {
        await sendJSON(api.rag.lock(documentId.value!), 'DELETE', {
            lock_token: lockToken.value,
        });
        lockToken.value = null;
    });
}
// 所有草稿變更串行化（佇列），避免 blur 自動存與結構操作併發互相覆蓋
let opQueue: Promise<unknown> = Promise.resolve();
function mutate(ops: unknown[]) {
    if (!canEdit.value || !ops.length) {
        return;
    }

    busy.value = true;
    error.value = null;
    opQueue = opQueue
        .then(async () => {
            const r = await sendJSON(
                api.rag.chunks(documentId.value!),
                'POST',
                {
                    lock_token: lockToken.value,
                    ops,
                },
            );
            chunks.value = r.chunks;
            nearDups.value = r.near_duplicates ?? [];
            snapshotChunks();
        })
        .catch((e) => {
            error.value = e instanceof Error ? e.message : String(e);
        })
        .finally(() => {
            busy.value = false;
        });
}
// 離開欄位時自動存（內容/情境有變才送，避免無謂清掉向量快取）
function saveChunkField(c: Chunk) {
    const base = saved.value[c.index];

    if (base && base.content === c.content && base.context === c.context) {
        return;
    }

    mutate([
        { op: 'set_content', index: c.index, content: c.content },
        { op: 'set_context', index: c.index, context: c.context },
    ]);
}
// 記錄游標位置（textarea 互動時）
function trackCaret(c: Chunk, e: Event) {
    const el = e.target as HTMLTextAreaElement;
    caret.value = { index: c.index, pos: el.selectionStart ?? 0 };
}
// 此塊內的「有效游標位置」（0<pos<長度 才有效，否則 null）
function cursorPos(c: Chunk): number | null {
    if (caret.value && caret.value.index === c.index) {
        const p = caret.value.pos;

        if (p > 0 && p < c.content.length) {
            return p;
        }
    }

    return null;
}
function isLast(c: Chunk): boolean {
    return c.index >= chunks.value.length - 1;
}

// 併上：有游標→前半併入上塊、本塊留後半；無游標→整塊併入上塊
function mergeUp(c: Chunk) {
    if (c.index === 0) {
        return;
    }

    const prev = chunks.value.find((x) => x.index === c.index - 1);
    const p = cursorPos(c);
    caret.value = null;

    if (p === null) {
        mutate([{ op: 'merge', index: c.index - 1 }]);
    } else if (prev) {
        mutate([
            {
                op: 'set_content',
                index: c.index - 1,
                content: prev.content + c.content.slice(0, p),
            },
            { op: 'set_content', index: c.index, content: c.content.slice(p) },
        ]);
    }
}
// 併下：有游標→後半併入下塊、本塊留前半；無游標→整塊併入下塊
function mergeDown(c: Chunk) {
    if (isLast(c)) {
        return;
    }

    const next = chunks.value.find((x) => x.index === c.index + 1);
    const p = cursorPos(c);
    caret.value = null;

    if (p === null) {
        mutate([{ op: 'merge', index: c.index }]);
    } else if (next) {
        mutate([
            {
                op: 'set_content',
                index: c.index + 1,
                content: c.content.slice(p) + next.content,
            },
            {
                op: 'set_content',
                index: c.index,
                content: c.content.slice(0, p),
            },
        ]);
    }
}
function splitChunk(c: Chunk) {
    const p = cursorPos(c);

    if (p === null) {
        return;
    }

    caret.value = null;
    mutate([{ op: 'split', index: c.index, at: p }]);
}
function deleteChunk(c: Chunk) {
    caret.value = null;
    mutate([{ op: 'delete', index: c.index }]);
}
function runTest() {
    if (!testQuery.value.trim()) {
        return;
    }

    run(async () => {
        const r = await sendJSON(api.rag.testQuery(documentId.value!), 'POST', {
            query: testQuery.value,
            top_k: 5,
        });
        testResults.value = r.data;
    });
}
function hitFor(index: number): TestHit | undefined {
    return testResults.value.find((h) => h.chunk_index === index);
}
function doCommit() {
    run(async () => {
        const r = await sendJSON(api.rag.commit(documentId.value!), 'POST', {
            lock_token: lockToken.value,
        });
        committedInfo.value = `已儲存:${r.chunks} 塊(本次 embed ${r.embedded} 塊)`;
        showCommitModal.value = false;
        await loadChunks();
    });
}

function resetFlow() {
    releaseLock();
    step.value = 'files';
    selectedFile.value = null;
    selectedKb.value = null;
    documentId.value = null;
    chunks.value = [];
    testResults.value = [];
    nearDups.value = [];
    committedInfo.value = null;
}

// ── dashboard / 視圖切換 ───────────────────────────────────
function loadDashboard() {
    run(async () => {
        dash.value = (await getJSON(api.rag.dashboard())).data;
    });
}
function enterWizard() {
    resetFlow();
    view.value = 'wizard';
    loadFiles();
    loadKbs();
}
function backToDashboard() {
    resetFlow();
    view.value = 'dashboard';
    loadDashboard();
}
// ── qa（問答）─────────────────────────────────────────────
function enterQa() {
    resetFlow();
    view.value = 'qa';
    qaAnswer.value = null;
    qaSources.value = [];

    if (!dash.value.length) {
        loadDashboard();
    }
}
// 只有已落庫（向量庫有塊）的庫能問答
const qaKbs = computed(() => dash.value.filter((k) => k.vector_count > 0));
function askQuestion() {
    if (!qaKbId.value || !qaQuery.value.trim()) {
        return;
    }

    run(async () => {
        qaAnswer.value = null;
        qaSources.value = [];
        const r = await sendJSON(api.rag.ask(qaKbId.value!), 'POST', {
            query: qaQuery.value,
        });
        qaAnswer.value = r.data.answer;
        qaSources.value = r.data.sources ?? [];
    });
}
// 檔為主視角：把各庫文件依 drive_file_id 收攏
const byFile = computed(() => {
    const map = new Map<
        string,
        {
            name: string;
            in: { kb: string; chunk_count: number; status: string }[];
        }
    >();

    for (const kb of dash.value) {
        for (const d of kb.documents) {
            const e = map.get(d.drive_file_id) ?? { name: d.name, in: [] };
            e.in.push({
                kb: kb.name,
                chunk_count: d.chunk_count,
                status: d.status,
            });
            map.set(d.drive_file_id, e);
        }
    }

    return [...map.values()];
});

function docStatusLabel(s: string): string {
    return { draft: '草稿', dirty: '⚠ 待更新', committed: '✓ 已落庫' }[s] ?? s;
}
function chunkTotal(docs: DashDoc[]): number {
    return docs.reduce((sum, d) => sum + d.chunk_count, 0);
}

onMounted(() => {
    loadDashboard();
});
</script>

<template>
    <AppLayout>
        <Head title="RAG 知識庫" />

        <div class="mx-auto max-w-screen-xl px-[18px] pb-24 md:px-8">
            <!-- Header -->
            <div class="mb-6 pt-8">
                <span
                    class="binary-label mb-2 block text-xs font-bold text-[var(--binary-primary)] uppercase"
                >
                    &gt; rag_knowledge_base
                </span>
                <h1 class="binary-page-title">RAG 知識庫</h1>
                <p class="mt-3 text-sm text-[var(--binary-text-muted)]">
                    {{
                        view === 'wizard'
                            ? '選 Drive 檔 → 選知識庫 → 互動切塊/測試 → 落庫檢索'
                            : view === 'qa'
                              ? '選知識庫提問 → 檢索 top-k → LLM 依命中內容作答（附出處）'
                              : '檢視各知識庫的文件、塊數與向量同步狀態'
                    }}
                </p>
            </div>

            <!-- 視圖切換（切換 content：總覽 / 精靈）-->
            <div
                class="mb-5 flex items-center gap-6 border-b border-[var(--binary-outline-variant)]"
            >
                <button
                    class="binary-label border-b-2 pb-2.5 text-[10px] uppercase transition"
                    :class="
                        view === 'dashboard'
                            ? 'border-[var(--binary-primary)] text-[var(--binary-primary)]'
                            : 'border-transparent text-[var(--binary-outline)] hover:text-[var(--binary-text)]'
                    "
                    @click="backToDashboard"
                >
                    總覽
                </button>
                <button
                    class="binary-label border-b-2 pb-2.5 text-[10px] uppercase transition"
                    :class="
                        view === 'wizard'
                            ? 'border-[var(--binary-primary)] text-[var(--binary-primary)]'
                            : 'border-transparent text-[var(--binary-outline)] hover:text-[var(--binary-text)]'
                    "
                    @click="enterWizard"
                >
                    新增 / 編輯
                </button>
                <button
                    class="binary-label border-b-2 pb-2.5 text-[10px] uppercase transition"
                    :class="
                        view === 'qa'
                            ? 'border-[var(--binary-primary)] text-[var(--binary-primary)]'
                            : 'border-transparent text-[var(--binary-outline)] hover:text-[var(--binary-text)]'
                    "
                    @click="enterQa"
                >
                    問答
                </button>
            </div>

            <!-- 共用提示 -->
            <div
                v-if="error"
                class="mb-4 rounded-lg border border-[var(--binary-tertiary)] px-4 py-2 text-sm text-[var(--binary-tertiary)]"
            >
                {{ error }}
            </div>
            <div
                v-if="committedInfo"
                class="mb-4 rounded-lg border border-[var(--binary-primary)] px-4 py-2 text-sm text-[var(--binary-primary)]"
            >
                {{ committedInfo }}
            </div>

            <!-- ░░ 總覽 dashboard ░░ -->
            <div v-if="view === 'dashboard'">
                <div
                    class="mb-4 flex w-fit gap-1 rounded-lg border border-[var(--binary-outline-variant)] p-1"
                >
                    <button
                        class="binary-label rounded-md px-3 py-1 text-[10px] uppercase transition"
                        :class="
                            dashTab === 'by_kb'
                                ? 'bg-[var(--binary-surface-high)] text-[var(--binary-primary)]'
                                : 'text-[var(--binary-outline)] hover:text-[var(--binary-text)]'
                        "
                        @click="dashTab = 'by_kb'"
                    >
                        庫為主
                    </button>
                    <button
                        class="binary-label rounded-md px-3 py-1 text-[10px] uppercase transition"
                        :class="
                            dashTab === 'by_file'
                                ? 'bg-[var(--binary-surface-high)] text-[var(--binary-primary)]'
                                : 'text-[var(--binary-outline)] hover:text-[var(--binary-text)]'
                        "
                        @click="dashTab = 'by_file'"
                    >
                        檔為主
                    </button>
                </div>

                <p
                    v-if="!dash.length && !busy"
                    class="text-sm text-[var(--binary-outline)]"
                >
                    還沒有知識庫，按「＋ 新增/編輯文件」開始。
                </p>

                <!-- 庫為主 -->
                <div v-if="dashTab === 'by_kb'" class="space-y-4">
                    <div
                        v-for="kb in dash"
                        :key="kb.id"
                        class="binary-glass rounded-xl p-4"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div
                                    class="text-sm font-bold text-[var(--binary-text)]"
                                >
                                    {{ kb.name }}
                                </div>
                                <div
                                    class="binary-label text-[10px] text-[var(--binary-outline)]"
                                >
                                    {{ kb.embedding_model }} ·
                                    {{ kb.dimensions }}維
                                </div>
                            </div>
                            <div
                                class="binary-label shrink-0 text-right text-[10px] text-[var(--binary-outline)]"
                            >
                                向量庫 {{ kb.vector_count }} 塊 · 目前
                                {{ chunkTotal(kb.documents) }} 塊
                            </div>
                        </div>
                        <div class="mt-3 space-y-1">
                            <div
                                v-for="d in kb.documents"
                                :key="d.drive_file_id"
                                class="flex items-center justify-between gap-3 border-t border-[var(--binary-outline-variant)] pt-1.5 text-xs"
                            >
                                <span
                                    class="min-w-0 truncate text-[var(--binary-text)]"
                                    >{{ d.name }}</span
                                >
                                <span
                                    class="binary-label flex shrink-0 items-center gap-2 text-[10px]"
                                >
                                    <span
                                        :class="
                                            d.status === 'dirty'
                                                ? 'text-[var(--binary-tertiary)]'
                                                : d.status === 'committed'
                                                  ? 'text-[var(--binary-primary)]'
                                                  : 'text-[var(--binary-outline)]'
                                        "
                                        >{{ docStatusLabel(d.status) }}</span
                                    >
                                    <span class="text-[var(--binary-outline)]"
                                        >· {{ d.chunk_count }} 塊</span
                                    >
                                </span>
                            </div>
                            <p
                                v-if="!kb.documents.length"
                                class="text-[10px] text-[var(--binary-outline)]"
                            >
                                （無文件）
                            </p>
                        </div>
                    </div>
                </div>

                <!-- 檔為主 -->
                <div v-else class="space-y-3">
                    <div
                        v-for="f in byFile"
                        :key="f.name"
                        class="binary-glass rounded-xl p-4"
                    >
                        <div class="text-sm text-[var(--binary-text)]">
                            {{ f.name }}
                            <span
                                class="binary-label text-[10px] text-[var(--binary-outline)]"
                                >· 在 {{ f.in.length }} 個庫</span
                            >
                        </div>
                        <div class="mt-2 space-y-1">
                            <div
                                v-for="(loc, i) in f.in"
                                :key="i"
                                class="flex items-center justify-between gap-3 text-xs"
                            >
                                <span class="text-[var(--binary-text-muted)]">{{
                                    loc.kb
                                }}</span>
                                <span
                                    class="binary-label flex shrink-0 items-center gap-2 text-[10px]"
                                >
                                    <span
                                        :class="
                                            loc.status === 'dirty'
                                                ? 'text-[var(--binary-tertiary)]'
                                                : loc.status === 'committed'
                                                  ? 'text-[var(--binary-primary)]'
                                                  : 'text-[var(--binary-outline)]'
                                        "
                                        >{{ docStatusLabel(loc.status) }}</span
                                    >
                                    <span class="text-[var(--binary-outline)]"
                                        >· {{ loc.chunk_count }} 塊</span
                                    >
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ░░ 問答 qa ░░ -->
            <div v-if="view === 'qa'" class="max-w-3xl space-y-4">
                <p
                    v-if="!qaKbs.length && !busy"
                    class="text-sm text-[var(--binary-outline)]"
                >
                    還沒有已落庫的知識庫。請先在「新增 /
                    編輯」把文件落庫，才能問答。
                </p>

                <template v-else>
                    <!-- 選庫 -->
                    <div>
                        <label
                            class="binary-label mb-1.5 block text-[10px] text-[var(--binary-outline)] uppercase"
                            >知識庫</label
                        >
                        <select
                            v-model="qaKbId"
                            class="w-full rounded-lg border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-low)] px-3 py-2 text-sm text-[var(--binary-text)]"
                        >
                            <option :value="null" disabled>
                                選一個知識庫…
                            </option>
                            <option
                                v-for="kb in qaKbs"
                                :key="kb.id"
                                :value="kb.id"
                            >
                                {{ kb.name }}（{{ kb.vector_count }} 塊）
                            </option>
                        </select>
                    </div>

                    <!-- 問題 -->
                    <div>
                        <textarea
                            v-model="qaQuery"
                            rows="3"
                            placeholder="輸入問題，依此庫已落庫的內容作答"
                            class="w-full resize-y rounded-lg border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-low)] px-3 py-2 text-sm text-[var(--binary-text)]"
                            @keydown.ctrl.enter="askQuestion"
                            @keydown.meta.enter="askQuestion"
                        />
                        <div class="mt-2 flex items-center gap-3">
                            <button
                                class="binary-button"
                                :disabled="busy || !qaKbId || !qaQuery.trim()"
                                @click="askQuestion"
                            >
                                {{ busy ? '思考中…' : '提問' }}
                            </button>
                            <span
                                class="binary-label text-[10px] text-[var(--binary-outline)]"
                                >⌘/Ctrl + Enter 送出</span
                            >
                        </div>
                    </div>

                    <!-- 回答 -->
                    <div v-if="qaAnswer" class="binary-glass rounded-xl p-4">
                        <div
                            class="binary-label mb-2 text-[10px] text-[var(--binary-primary)] uppercase"
                        >
                            回答
                        </div>
                        <div
                            class="text-sm leading-relaxed whitespace-pre-wrap text-[var(--binary-text)]"
                        >
                            {{ qaAnswer }}
                        </div>
                    </div>

                    <!-- 出處 -->
                    <div v-if="qaSources.length" class="space-y-2">
                        <div
                            class="binary-label text-[10px] text-[var(--binary-outline)] uppercase"
                        >
                            出處（檢索命中 {{ qaSources.length }} 塊）
                        </div>
                        <details
                            v-for="(s, i) in qaSources"
                            :key="i"
                            class="rounded-lg border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-low)] px-3 py-2"
                        >
                            <summary
                                class="flex cursor-pointer items-center justify-between gap-2 text-xs text-[var(--binary-text-muted)]"
                            >
                                <span class="min-w-0 truncate">
                                    [{{ i + 1 }}]
                                    {{ s.metadata?.file_name ?? '?' }} #{{
                                        s.metadata?.chunk_index ?? '?'
                                    }}
                                </span>
                                <span
                                    v-if="s.similarity != null"
                                    class="binary-label shrink-0 text-[10px] text-[var(--binary-outline)]"
                                    >sim {{ s.similarity.toFixed(3) }}</span
                                >
                            </summary>
                            <div
                                class="mt-2 text-xs leading-relaxed whitespace-pre-wrap text-[var(--binary-text-muted)]"
                            >
                                {{ s.content }}
                            </div>
                        </details>
                    </div>
                </template>
            </div>

            <!-- ░░ 精靈 wizard ░░ -->
            <div v-if="view === 'wizard'">
                <!-- Stepper -->
                <div
                    class="binary-label mb-5 flex items-center gap-2 text-[10px] uppercase"
                >
                    <span
                        :class="
                            step === 'files'
                                ? 'text-[var(--binary-primary)]'
                                : 'text-[var(--binary-outline)]'
                        "
                        >1 選檔</span
                    >
                    <span class="text-[var(--binary-outline-variant)]">/</span>
                    <span
                        :class="
                            step === 'kb'
                                ? 'text-[var(--binary-primary)]'
                                : 'text-[var(--binary-outline)]'
                        "
                        >2 選庫</span
                    >
                    <span class="text-[var(--binary-outline-variant)]">/</span>
                    <span
                        :class="
                            step === 'editor'
                                ? 'text-[var(--binary-primary)]'
                                : 'text-[var(--binary-outline)]'
                        "
                        >3 編輯</span
                    >
                    <button
                        v-if="step !== 'files'"
                        class="binary-ghost-button ml-auto px-3 py-1 text-[10px]"
                        @click="resetFlow"
                    >
                        重來
                    </button>
                </div>

                <!-- Step 1: files -->
                <div v-if="step === 'files'" class="space-y-2">
                    <p
                        v-if="!driveFiles.length && !busy"
                        class="text-sm text-[var(--binary-outline)]"
                    >
                        Drive 資料夾沒有可萃取的檔案(或尚未設定
                        RAG_DRIVE_FOLDER_ID)。
                    </p>
                    <button
                        v-for="f in driveFiles"
                        :key="f.id"
                        class="binary-glass flex w-full items-center justify-between rounded-xl px-4 py-3 text-left transition hover:border-[var(--binary-primary)]"
                        @click="pickFile(f)"
                    >
                        <div>
                            <div class="text-sm text-[var(--binary-text)]">
                                {{ f.name }}
                            </div>
                            <div
                                class="binary-label text-[10px] text-[var(--binary-outline)]"
                            >
                                {{ f.mime_type }}
                            </div>
                        </div>
                        <span
                            class="binary-label text-[10px] text-[var(--binary-primary)]"
                            >選取 →</span
                        >
                    </button>
                </div>

                <!-- Step 2: knowledge base -->
                <div v-else-if="step === 'kb'" class="space-y-4">
                    <div class="text-sm text-[var(--binary-text-muted)]">
                        檔案：<span class="text-[var(--binary-text)]">{{
                            selectedFile?.name
                        }}</span>
                    </div>

                    <!-- 既有庫（在上） -->
                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <span
                                class="binary-label text-[10px] text-[var(--binary-outline)] uppercase"
                            >
                                既有知識庫
                            </span>
                            <div
                                v-if="kbs.length"
                                class="flex items-center gap-2"
                            >
                                <button
                                    v-if="manageMode && selectedKbIds.length"
                                    class="binary-label rounded px-2 py-1 text-[10px] text-[var(--binary-tertiary)] uppercase hover:bg-[var(--binary-surface-high)]"
                                    :disabled="busy"
                                    @click="deleteSelectedKbs"
                                >
                                    🗑 刪除選取 ({{ selectedKbIds.length }})
                                </button>
                                <button
                                    class="binary-label rounded px-2 py-1 text-[10px] uppercase hover:bg-[var(--binary-surface-high)]"
                                    :class="
                                        manageMode
                                            ? 'text-[var(--binary-primary)]'
                                            : 'text-[var(--binary-outline)]'
                                    "
                                    @click="toggleManage"
                                >
                                    {{ manageMode ? '完成' : '管理' }}
                                </button>
                            </div>
                        </div>
                        <p
                            v-if="!kbs.length"
                            class="text-sm text-[var(--binary-outline)]"
                        >
                            還沒有知識庫，先在下方建立一個。
                        </p>
                        <button
                            v-for="kb in kbs"
                            :key="kb.id"
                            class="binary-glass flex w-full items-center justify-between gap-3 rounded-xl px-4 py-3 text-left transition hover:border-[var(--binary-primary)]"
                            :class="
                                manageMode && selectedKbIds.includes(kb.id)
                                    ? 'border-[var(--binary-tertiary)]'
                                    : ''
                            "
                            :disabled="busy"
                            @click="
                                manageMode
                                    ? toggleKbSelect(kb.id)
                                    : chooseKb(kb)
                            "
                        >
                            <span
                                v-if="manageMode"
                                class="shrink-0 text-sm"
                                :class="
                                    selectedKbIds.includes(kb.id)
                                        ? 'text-[var(--binary-tertiary)]'
                                        : 'text-[var(--binary-outline)]'
                                "
                            >
                                {{ selectedKbIds.includes(kb.id) ? '☑' : '☐' }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm text-[var(--binary-text)]">
                                    {{ kb.name }}
                                </div>
                                <div
                                    class="binary-label text-[10px] text-[var(--binary-outline)]"
                                >
                                    模型 {{ kb.collection.split('__')[1] }}
                                </div>
                                <div
                                    v-if="fileStatusLabel(kb.file_status)"
                                    class="binary-label mt-0.5 text-[10px]"
                                    :class="
                                        kb.file_status === 'dirty'
                                            ? 'text-[var(--binary-tertiary)]'
                                            : 'text-[var(--binary-primary)]'
                                    "
                                >
                                    {{ fileStatusLabel(kb.file_status) }}
                                </div>
                            </div>
                            <span
                                class="binary-label shrink-0 text-[10px] text-[var(--binary-outline)]"
                            >
                                {{ kb.committed_count }} 已落庫<template
                                    v-if="kb.draft_count"
                                >
                                    · {{ kb.draft_count }} 草稿</template
                                >
                            </span>
                        </button>
                    </div>

                    <!-- 建立新庫（在下） -->
                    <div class="binary-glass rounded-xl p-4">
                        <div
                            class="binary-label mb-2 text-[10px] text-[var(--binary-outline)] uppercase"
                        >
                            建立新庫
                        </div>
                        <div class="flex gap-2">
                            <input
                                v-model="newKbName"
                                class="binary-input min-w-0 flex-1"
                                placeholder="知識庫名稱（如：技術手冊）"
                                @keyup.enter="createKb"
                            />
                            <button
                                class="binary-button w-auto shrink-0 px-4 whitespace-nowrap"
                                :disabled="busy"
                                @click="createKb"
                            >
                                建立並使用
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Step 3: editor -->
                <div v-else class="space-y-4">
                    <!-- toolbar -->
                    <div
                        class="binary-glass flex flex-wrap items-center gap-3 rounded-xl px-4 py-3"
                    >
                        <div class="text-sm text-[var(--binary-text)]">
                            {{ selectedKb?.name }} · {{ selectedFile?.name }}
                        </div>
                        <div
                            v-if="diff"
                            class="binary-label text-[10px] text-[var(--binary-outline)]"
                        >
                            diff: 未變 {{ diff.unchanged }} / 新增
                            {{ diff.added }} / 刪除 {{ diff.removed }}
                        </div>
                        <div class="ml-auto flex flex-wrap items-center gap-2">
                            <button
                                class="binary-ghost-button shrink-0 px-3 py-1.5 text-xs whitespace-nowrap"
                                :disabled="busy"
                                title="同步草稿（取得 Claude／他處經 MCP 的最新修改）"
                                @click="refreshDraft"
                            >
                                🔄 同步草稿
                            </button>
                            <span
                                v-if="lockedByOther"
                                class="binary-label shrink-0 rounded bg-[var(--binary-surface-high)] px-2 py-1 text-[10px] whitespace-nowrap text-[var(--binary-tertiary)]"
                            >
                                🔒 其他人編輯中
                            </span>
                            <button
                                v-if="!lockToken && !lockedByOther"
                                class="binary-button shrink-0 px-3 py-1.5 text-xs whitespace-nowrap"
                                @click="acquireLock"
                            >
                                🔒 上鎖編輯
                            </button>
                            <template v-if="lockToken">
                                <button
                                    class="binary-ghost-button shrink-0 px-3 py-1.5 text-xs whitespace-nowrap"
                                    title="複製 lock token（可貼給 Claude 接手編輯）"
                                    @click="copyToken"
                                >
                                    📋 複製 token
                                </button>
                                <button
                                    class="binary-ghost-button shrink-0 px-3 py-1.5 text-xs whitespace-nowrap"
                                    :disabled="busy"
                                    @click="reproposeForce"
                                >
                                    ↻ 重新切塊
                                </button>
                                <button
                                    class="binary-ghost-button shrink-0 px-3 py-1.5 text-xs whitespace-nowrap"
                                    @click="releaseLock"
                                >
                                    🔓 解鎖
                                </button>
                                <button
                                    class="binary-button shrink-0 px-3 py-1.5 text-xs whitespace-nowrap"
                                    @click="showCommitModal = true"
                                >
                                    儲存
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- test query -->
                    <div
                        class="binary-glass flex items-center gap-2 rounded-xl px-4 py-3"
                    >
                        <input
                            v-model="testQuery"
                            class="binary-input flex-1"
                            placeholder="測試查詢:打一句話看會撈到哪些塊"
                            @keyup.enter="runTest"
                        />
                        <button
                            class="binary-ghost-button px-4 py-2 text-xs"
                            :disabled="busy"
                            @click="runTest"
                        >
                            測試
                        </button>
                    </div>

                    <!-- near-dup warning -->
                    <div
                        v-if="nearDups.length"
                        class="rounded-lg border border-[var(--binary-tertiary)] px-4 py-2 text-xs text-[var(--binary-tertiary)]"
                    >
                        ⚠ 近似重複:
                        <span v-for="(d, i) in nearDups" :key="i"
                            >#{{ d.a }}↔#{{ d.b }} ({{ d.similarity }})
                        </span>
                    </div>

                    <!-- chunk cards -->
                    <div class="space-y-3">
                        <div
                            v-for="c in chunks"
                            :key="c.index"
                            class="rounded-xl border bg-[var(--binary-surface-container)] p-4"
                            :class="
                                hitFor(c.index)
                                    ? 'border-[var(--binary-primary)]'
                                    : 'border-[var(--binary-outline-variant)]'
                            "
                        >
                            <div
                                class="binary-label mb-2 flex items-center gap-3 text-[10px] text-[var(--binary-outline)]"
                            >
                                <span>#{{ c.index }}</span>
                                <span>{{ c.chars }} 字</span>
                                <span
                                    :class="
                                        c.embedded
                                            ? 'text-[var(--binary-primary)]'
                                            : ''
                                    "
                                >
                                    {{ c.embedded ? '已向量' : '未向量' }}
                                </span>
                                <span
                                    v-if="hitFor(c.index)"
                                    class="ml-auto text-[var(--binary-primary)]"
                                >
                                    命中 sim
                                    {{ hitFor(c.index)!.similarity.toFixed(3) }}
                                </span>
                            </div>
                            <input
                                v-model="c.context"
                                :disabled="!canEdit"
                                class="binary-input mb-2 text-xs"
                                placeholder="情境前綴(選填,Contextual Retrieval)"
                                @blur="saveChunkField(c)"
                            />
                            <textarea
                                v-model="c.content"
                                :disabled="!canEdit"
                                rows="4"
                                class="binary-input w-full text-sm"
                                @blur="saveChunkField(c)"
                                @click="trackCaret(c, $event)"
                                @keyup="trackCaret(c, $event)"
                                @select="trackCaret(c, $event)"
                            />
                            <div
                                v-if="canEdit"
                                class="mt-2 flex flex-wrap gap-2"
                            >
                                <button
                                    v-if="c.index > 0"
                                    class="binary-ghost-button px-3 py-1 text-[10px]"
                                    @click="mergeUp(c)"
                                >
                                    {{
                                        cursorPos(c) !== null
                                            ? '↑ 併前半'
                                            : '↑ 整塊併上'
                                    }}
                                </button>
                                <button
                                    class="binary-ghost-button px-3 py-1 text-[10px] disabled:opacity-40"
                                    :disabled="cursorPos(c) === null"
                                    title="把游標點在要切開的位置再按"
                                    @click="splitChunk(c)"
                                >
                                    ✂ 分割
                                </button>
                                <button
                                    v-if="!isLast(c)"
                                    class="binary-ghost-button px-3 py-1 text-[10px]"
                                    @click="mergeDown(c)"
                                >
                                    {{
                                        cursorPos(c) !== null
                                            ? '併後半 ↓'
                                            : '整塊併下 ↓'
                                    }}
                                </button>
                                <button
                                    class="binary-ghost-button px-3 py-1 text-[10px] text-[var(--binary-tertiary)]"
                                    @click="deleteChunk(c)"
                                >
                                    🗑 刪除
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- commit confirmation modal -->
                <div
                    v-if="showCommitModal"
                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                    @click.self="showCommitModal = false"
                >
                    <div class="binary-glass w-full max-w-lg rounded-xl p-6">
                        <h2
                            class="binary-display mb-3 text-lg font-bold text-[var(--binary-primary)]"
                        >
                            儲存確認
                        </h2>
                        <p class="mb-2 text-sm text-[var(--binary-text)]">
                            將把 <b>{{ chunks.length }}</b> 塊 embed
                            後寫入向量庫
                            <code class="text-[var(--binary-primary)]">{{
                                selectedKb?.collection
                            }}</code
                            >。
                        </p>
                        <p
                            v-if="diff"
                            class="mb-4 text-xs text-[var(--binary-text-muted)]"
                        >
                            對比上次:未變 {{ diff.unchanged }} / 新增
                            {{ diff.added }} / 刪除
                            {{ diff.removed }}(未變塊重用快取,不重 embed)。
                        </p>
                        <div class="flex justify-end gap-2">
                            <button
                                class="binary-ghost-button px-4 py-2 text-sm"
                                @click="showCommitModal = false"
                            >
                                取消
                            </button>
                            <button
                                class="binary-button px-4 py-2 text-sm"
                                :disabled="busy"
                                @click="doCommit"
                            >
                                確認儲存
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

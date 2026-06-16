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

type Step = 'files' | 'kb' | 'editor';

const step = ref<Step>('files');
const error = ref<string | null>(null);
const busy = ref(false);

// step 1
const driveFiles = ref<DriveFile[]>([]);
const selectedFile = ref<DriveFile | null>(null);

// step 2
const kbs = ref<Kb[]>([]);
const selectedKb = ref<Kb | null>(null);
const newKbName = ref('');

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
function loadKbs() {
    run(async () => {
        kbs.value = (await getJSON(api.rag.kbs())).data;
    });
}
function pickFile(f: DriveFile) {
    selectedFile.value = f;
    step.value = 'kb';
    // 取得各 KB 對此檔狀態(若已選/有 KB,延後在選 KB 時顯示)
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
        kbs.value = (await getJSON(api.rag.kbs())).data;
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
        await loadChunks();
        step.value = 'editor';
    });
}

// ── step 3: editor ────────────────────────────────────────
async function loadChunks() {
    const r = await getJSON(api.rag.chunks(documentId.value!));
    chunks.value = r.chunks;
    lockedByOther.value = !!r.lock && r.lock.locked_by !== user.value?.id;
}
function acquireLock() {
    run(async () => {
        const r = await sendJSON(api.rag.lock(documentId.value!), 'POST');
        lockToken.value = r.lock_token;
        lockedByOther.value = false;
    });
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
function applyOps(ops: unknown[]) {
    if (!canEdit.value) {
        return;
    }

    run(async () => {
        const r = await sendJSON(api.rag.chunks(documentId.value!), 'POST', {
            lock_token: lockToken.value,
            ops,
        });
        chunks.value = r.chunks;
        nearDups.value = r.near_duplicates ?? [];
    });
}
function saveChunk(c: Chunk) {
    applyOps([
        { op: 'set_content', index: c.index, content: c.content },
        { op: 'set_context', index: c.index, context: c.context },
    ]);
}
function splitChunk(c: Chunk) {
    const at = Math.floor(c.content.length / 2);
    applyOps([{ op: 'split', index: c.index, at }]);
}
function mergeChunk(c: Chunk) {
    applyOps([{ op: 'merge', index: c.index }]);
}
function deleteChunk(c: Chunk) {
    applyOps([{ op: 'delete', index: c.index }]);
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
        committedInfo.value = `已落庫:${r.chunks} 塊(本次 embed ${r.embedded} 塊)`;
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

onMounted(() => {
    loadFiles();
    loadKbs();
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
                    選 Drive 檔 → 選知識庫 → 互動切塊/測試 → 落庫檢索
                </p>
            </div>

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
                    <div
                        class="binary-label text-[10px] text-[var(--binary-outline)] uppercase"
                    >
                        既有知識庫
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
                        class="binary-glass flex w-full items-center justify-between rounded-xl px-4 py-3 text-left transition hover:border-[var(--binary-primary)]"
                        :disabled="busy"
                        @click="chooseKb(kb)"
                    >
                        <div class="min-w-0">
                            <div class="text-sm text-[var(--binary-text)]">
                                {{ kb.name }}
                            </div>
                            <div
                                class="binary-label text-[10px] text-[var(--binary-outline)]"
                            >
                                模型 {{ kb.collection.split('__')[1] }}
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
                    <div class="ml-auto flex items-center gap-2">
                        <span
                            v-if="lockedByOther"
                            class="binary-label rounded bg-[var(--binary-surface-high)] px-2 py-1 text-[10px] text-[var(--binary-tertiary)]"
                        >
                            🔒 其他人編輯中
                        </span>
                        <button
                            v-if="!lockToken && !lockedByOther"
                            class="binary-button px-3 py-1.5 text-xs"
                            @click="acquireLock"
                        >
                            🔒 上鎖編輯
                        </button>
                        <template v-if="lockToken">
                            <code
                                class="rounded bg-[var(--binary-surface-high)] px-2 py-1 text-[10px] text-[var(--binary-primary)]"
                                :title="lockToken"
                            >
                                token:{{ lockToken.slice(0, 8) }}…
                            </code>
                            <button
                                class="binary-ghost-button px-3 py-1.5 text-xs"
                                @click="releaseLock"
                            >
                                🔓 解鎖
                            </button>
                            <button
                                class="binary-button px-3 py-1.5 text-xs"
                                @click="showCommitModal = true"
                            >
                                落庫…
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
                        />
                        <textarea
                            v-model="c.content"
                            :disabled="!canEdit"
                            rows="4"
                            class="binary-input w-full text-sm"
                        />
                        <div v-if="canEdit" class="mt-2 flex flex-wrap gap-2">
                            <button
                                class="binary-ghost-button px-3 py-1 text-[10px]"
                                @click="saveChunk(c)"
                            >
                                儲存
                            </button>
                            <button
                                class="binary-ghost-button px-3 py-1 text-[10px]"
                                @click="splitChunk(c)"
                            >
                                對半切
                            </button>
                            <button
                                class="binary-ghost-button px-3 py-1 text-[10px]"
                                @click="mergeChunk(c)"
                            >
                                與下塊合併
                            </button>
                            <button
                                class="binary-ghost-button px-3 py-1 text-[10px] text-[var(--binary-tertiary)]"
                                @click="deleteChunk(c)"
                            >
                                刪除
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
                        落庫確認
                    </h2>
                    <p class="mb-2 text-sm text-[var(--binary-text)]">
                        將把 <b>{{ chunks.length }}</b> 塊 embed 後寫入向量庫
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
                            確認落庫
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

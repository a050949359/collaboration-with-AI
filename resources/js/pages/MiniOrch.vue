<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { api } from '@/lib/routes';

type RunEntry = {
    run_id: string;
    status: string;
    created_at: string;
    result?: Record<string, unknown>;
};

const iframeRef = ref<HTMLIFrameElement | null>(null);
const showRunForm = ref(false);
const runBody = ref('{\n  "target_url": "http://",\n  "concurrency": 10,\n  "duration": 30\n}');
const parseError = ref('');
const submitting = ref(false);
const submitError = ref('');
const runs = ref<RunEntry[]>([]);

let autoRefreshTimer: ReturnType<typeof setInterval> | null = null;
let pollTimer: ReturnType<typeof setInterval> | null = null;

function refreshDashboard() {
    if (!iframeRef.value) return;
    // Force iframe reload by briefly clearing src
    const src = iframeRef.value.src;
    iframeRef.value.src = '';
    iframeRef.value.src = src;
}

function validateBody(): boolean {
    try {
        JSON.parse(runBody.value);
        parseError.value = '';
        return true;
    } catch (e: unknown) {
        parseError.value = e instanceof Error ? e.message : 'Invalid JSON';
        return false;
    }
}

async function submitRun() {
    if (!validateBody()) return;
    submitting.value = true;
    submitError.value = '';
    try {
        const res = await fetch(api.miniOrch.createRun(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: runBody.value,
            credentials: 'include',
        });
        const data = await res.json();
        if (!res.ok) {
            submitError.value = data?.message ?? `Error ${res.status}`;
            return;
        }
        // Accept run_id in common response shapes
        const runId: string = String(data.run_id ?? data.id ?? Object.values(data)[0] ?? '');
        runs.value.unshift({
            run_id: runId,
            status: data.status ?? 'running',
            created_at: new Date().toLocaleTimeString(),
        });
        showRunForm.value = false;
        setTimeout(refreshDashboard, 2000);
    } catch (e: unknown) {
        submitError.value = e instanceof Error ? e.message : 'Network error';
    } finally {
        submitting.value = false;
    }
}

async function pollRuns() {
    for (const run of runs.value) {
        if (run.status === 'running' || run.status === 'pending') {
            try {
                const res = await fetch(api.miniOrch.getRun(run.run_id), { credentials: 'include' });
                if (!res.ok) continue;
                const data = await res.json();
                run.status = data.status ?? run.status;
                if (run.status !== 'running' && run.status !== 'pending') {
                    run.result = data;
                }
            } catch { /* ignore */ }
        }
    }
}

function statusClass(status: string) {
    if (status === 'running' || status === 'pending') return 'text-[--binary-tertiary]';
    if (status === 'done' || status === 'completed' || status === 'success') return 'text-[--binary-primary]';
    return 'text-[--binary-text-muted]';
}

onMounted(() => {
    autoRefreshTimer = setInterval(refreshDashboard, 30_000);
    pollTimer = setInterval(pollRuns, 5_000);
});

onUnmounted(() => {
    if (autoRefreshTimer) clearInterval(autoRefreshTimer);
    if (pollTimer) clearInterval(pollTimer);
});
</script>

<template>
    <Head title="mini-orch" />
    <AppLayout>
        <div class="flex flex-col h-full min-h-[calc(100vh-4rem)] gap-3 p-4">

            <!-- Header bar -->
            <div class="flex items-center gap-3 flex-wrap">
                <span class="font-mono text-sm text-[--binary-primary] tracking-widest uppercase">mini-orch</span>
                <div class="flex items-center gap-2 ml-auto">
                    <button
                        class="px-3 py-1.5 text-xs font-mono rounded border border-[--binary-outline] text-[--binary-text-muted] hover:text-[--binary-text] hover:border-[--binary-primary] transition-colors"
                        @click="refreshDashboard"
                        title="Refresh dashboard"
                    >↺ refresh</button>
                    <button
                        class="px-3 py-1.5 text-xs font-mono rounded border transition-colors"
                        :class="showRunForm
                            ? 'border-[--binary-primary] text-[--binary-primary]'
                            : 'border-[--binary-outline] text-[--binary-text-muted] hover:text-[--binary-text] hover:border-[--binary-primary]'"
                        @click="showRunForm = !showRunForm; submitError = ''"
                    >{{ showRunForm ? '✕ cancel' : '+ new run' }}</button>
                </div>
            </div>

            <!-- New run form -->
            <Transition name="slide-down">
                <div
                    v-if="showRunForm"
                    class="rounded-lg border border-[--binary-outline] bg-[--binary-surface-low] p-4 space-y-3"
                >
                    <p class="text-xs font-mono text-[--binary-text-muted]">Request body (JSON)</p>
                    <textarea
                        v-model="runBody"
                        rows="6"
                        spellcheck="false"
                        class="w-full font-mono text-xs bg-[--binary-surface-dim] border border-[--binary-outline-variant] rounded p-3 text-[--binary-text] resize-y focus:outline-none focus:border-[--binary-primary] transition-colors"
                        @input="parseError = ''"
                    />
                    <p v-if="parseError" class="text-xs text-[--binary-tertiary] font-mono">{{ parseError }}</p>
                    <p v-if="submitError" class="text-xs text-[--binary-tertiary] font-mono">{{ submitError }}</p>
                    <div class="flex justify-end">
                        <button
                            class="px-4 py-1.5 text-xs font-mono rounded border border-[--binary-primary] text-[--binary-primary] hover:bg-[--binary-primary] hover:text-[--binary-on-primary-container] transition-colors disabled:opacity-40"
                            :disabled="submitting"
                            @click="submitRun"
                        >{{ submitting ? 'sending…' : '▶ trigger run' }}</button>
                    </div>
                </div>
            </Transition>

            <!-- Active runs -->
            <div v-if="runs.length" class="flex items-center gap-2 flex-wrap">
                <span class="text-xs font-mono text-[--binary-text-muted]">runs:</span>
                <div
                    v-for="run in runs"
                    :key="run.run_id"
                    class="flex items-center gap-1.5 px-2 py-1 rounded border border-[--binary-outline-variant] bg-[--binary-surface-container] text-xs font-mono"
                    :title="run.result ? JSON.stringify(run.result, null, 2) : undefined"
                >
                    <span
                        v-if="run.status === 'running' || run.status === 'pending'"
                        class="inline-block w-1.5 h-1.5 rounded-full bg-[--binary-tertiary] animate-pulse"
                    />
                    <span v-else class="inline-block w-1.5 h-1.5 rounded-full bg-[--binary-primary]" />
                    <span class="text-[--binary-text-muted]">{{ run.run_id }}</span>
                    <span :class="statusClass(run.status)">{{ run.status }}</span>
                    <span class="text-[--binary-text-muted] opacity-50">{{ run.created_at }}</span>
                </div>
            </div>

            <!-- Dashboard iframe -->
            <div class="flex-1 min-h-[480px] relative rounded-lg overflow-hidden border border-[--binary-outline-variant]">
                <iframe
                    ref="iframeRef"
                    :src="api.miniOrch.dashboard()"
                    class="absolute inset-0 w-full h-full border-0"
                    title="mini-orch dashboard"
                />
            </div>

        </div>
    </AppLayout>
</template>

<style scoped>
.slide-down-enter-active,
.slide-down-leave-active {
    transition: all 0.2s ease;
}
.slide-down-enter-from,
.slide-down-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}
</style>

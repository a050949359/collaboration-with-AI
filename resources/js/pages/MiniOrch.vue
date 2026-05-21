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

const showRunForm = ref(false);
const runBody = ref('{\n  "vus": 20,\n  "duration": "30s",\n  "api_url": "http://"\n}');
const parseError = ref('');
const submitting = ref(false);
const submitError = ref('');
const runs = ref<RunEntry[]>([]);
const iframeRef = ref<HTMLIFrameElement | null>(null);
const iframeSrc = ref(api.miniOrch.dashboard());

let autoRefreshTimer: ReturnType<typeof setInterval> | null = null;
let pollTimer: ReturnType<typeof setInterval> | null = null;

function refreshDashboard() {
    if (iframeRef.value) {
        iframeRef.value.src = api.miniOrch.dashboard();
    }
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
        const contentType = res.headers.get('content-type') ?? '';
        if (!contentType.includes('application/json')) {
            submitError.value = res.status === 401 || res.status === 403
                ? '請先登入才能觸發壓測'
                : `Error ${res.status}`;
            return;
        }
        const data = await res.json();
        if (!res.ok) {
            submitError.value = data?.message ?? `Error ${res.status}`;
            return;
        }
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
    autoRefreshTimer = setInterval(refreshDashboard, 600_000);
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
        <div class="mx-auto flex w-full max-w-screen-2xl flex-col px-6 pb-4 gap-3 overflow-hidden md:px-8" style="height: calc(100vh - 5rem); margin-top: 5rem;">

            <!-- Title -->
            <div class="flex flex-col gap-3 shrink-0">
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
                            class="w-full font-mono text-xs bg-[--binary-surface-dim] border border-[--binary-outline-variant] rounded p-3 text-[--binary-text] resize-none focus:outline-none focus:border-[--binary-primary] transition-colors"
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
            </div>

            <!-- iframe -->
            <div class="flex-1 min-h-0 rounded-lg overflow-hidden border border-[--binary-outline-variant]">
                <iframe
                    ref="iframeRef"
                    :src="iframeSrc"
                    class="w-full h-full"
                    frameborder="0"
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

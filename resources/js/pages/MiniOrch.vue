<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { api } from '@/lib/routes';

const { t } = useI18n();

type RunEntry = {
    run_id: string;
    status: string;
    created_at: string;
    result?: Record<string, unknown>;
};

const showRunForm = ref(false);
const runBody = ref(
    '{\n  "vus": 20,\n  "duration": "30s",\n  "api_url": "http://"\n}',
);
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
    if (!validateBody()) {
        return;
    }

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
            submitError.value = t('mini_orch.auth_required');

            return;
        }

        const data = await res.json();

        if (!res.ok) {
            submitError.value = data?.message ?? `Error ${res.status}`;

            return;
        }

        const runId: string = String(
            data.run_id ?? data.id ?? Object.values(data)[0] ?? '',
        );
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
                const res = await fetch(api.miniOrch.getRun(run.run_id), {
                    credentials: 'include',
                });

                if (!res.ok) {
                    continue;
                }

                const data = await res.json();
                run.status = data.status ?? run.status;

                if (run.status !== 'running' && run.status !== 'pending') {
                    run.result = data;
                }
            } catch {
                /* ignore */
            }
        }
    }
}

function statusClass(status: string) {
    if (status === 'running' || status === 'pending') {
        return 'text-[--binary-tertiary]';
    }

    if (status === 'done' || status === 'completed' || status === 'success') {
        return 'text-[--binary-primary]';
    }

    return 'text-[--binary-text-muted]';
}

onMounted(() => {
    autoRefreshTimer = setInterval(refreshDashboard, 600_000);
    pollTimer = setInterval(pollRuns, 5_000);
});

onUnmounted(() => {
    if (autoRefreshTimer) {
        clearInterval(autoRefreshTimer);
    }

    if (pollTimer) {
        clearInterval(pollTimer);
    }
});
</script>

<template>
    <Head :title="t('mini_orch.head_title')" />
    <AppLayout>
        <div
            class="mx-auto flex w-full max-w-screen-2xl flex-col gap-3 overflow-hidden px-[18px] pt-4 pb-4 md:px-8"
            style="height: calc(100dvh - 4rem)"
        >
            <!-- Title -->
            <div class="flex shrink-0 flex-col gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    <span
                        class="font-mono text-sm tracking-widest text-[--binary-primary] uppercase"
                        >mini-orch</span
                    >
                    <div class="ml-auto flex items-center gap-2">
                        <button
                            class="rounded border border-[--binary-outline] px-3 py-1.5 font-mono text-xs text-[--binary-text-muted] transition-colors hover:border-[--binary-primary] hover:text-[--binary-text]"
                            @click="refreshDashboard"
                            :title="t('mini_orch.refresh_title')"
                        >
                            <span class="md:hidden">↻</span>
                            <span class="hidden md:inline">{{
                                t('mini_orch.refresh')
                            }}</span>
                        </button>
                        <button
                            class="rounded border px-3 py-1.5 font-mono text-xs transition-colors"
                            :class="
                                showRunForm
                                    ? 'border-[--binary-primary] text-[--binary-primary]'
                                    : 'border-[--binary-outline] text-[--binary-text-muted] hover:border-[--binary-primary] hover:text-[--binary-text]'
                            "
                            :title="
                                showRunForm
                                    ? t('mini_orch.cancel')
                                    : t('mini_orch.new_run')
                            "
                            @click="
                                showRunForm = !showRunForm;
                                submitError = '';
                            "
                        >
                            <span class="md:hidden">{{
                                showRunForm ? '✕' : '＋'
                            }}</span>
                            <span class="hidden md:inline">{{
                                showRunForm
                                    ? t('mini_orch.cancel')
                                    : t('mini_orch.new_run')
                            }}</span>
                        </button>
                    </div>
                </div>

                <Transition name="slide-down">
                    <div
                        v-if="showRunForm"
                        class="space-y-3 rounded-none border border-[--binary-outline] bg-[--binary-surface-low] p-4 md:rounded-lg"
                    >
                        <p class="font-mono text-xs text-[--binary-text-muted]">
                            {{ t('mini_orch.body_label') }}
                        </p>
                        <textarea
                            v-model="runBody"
                            rows="6"
                            spellcheck="false"
                            class="w-full resize-none rounded border border-[--binary-outline-variant] bg-[--binary-surface-dim] p-3 font-mono text-xs text-[--binary-text] transition-colors focus:border-[--binary-primary] focus:outline-none"
                            @input="parseError = ''"
                        />
                        <p
                            v-if="parseError"
                            class="font-mono text-xs text-[--binary-tertiary]"
                        >
                            {{ parseError }}
                        </p>
                        <p
                            v-if="submitError"
                            class="font-mono text-xs text-[--binary-tertiary]"
                        >
                            {{ submitError }}
                        </p>
                        <div class="flex justify-end">
                            <button
                                class="rounded border border-[--binary-primary] px-4 py-1.5 font-mono text-xs text-[--binary-primary] transition-colors hover:bg-[--binary-primary] hover:text-[--binary-on-primary-container] disabled:opacity-40"
                                :disabled="submitting"
                                @click="submitRun"
                            >
                                {{
                                    submitting
                                        ? t('mini_orch.sending')
                                        : t('mini_orch.trigger')
                                }}
                            </button>
                        </div>
                    </div>
                </Transition>

                <div
                    v-if="runs.length"
                    class="flex flex-wrap items-center gap-2"
                >
                    <span
                        class="font-mono text-xs text-[--binary-text-muted]"
                        >{{ t('mini_orch.runs_label') }}</span
                    >
                    <div
                        v-for="run in runs"
                        :key="run.run_id"
                        class="flex items-center gap-1.5 rounded border border-[--binary-outline-variant] bg-[--binary-surface-container] px-2 py-1 font-mono text-xs"
                        :title="
                            run.result
                                ? JSON.stringify(run.result, null, 2)
                                : undefined
                        "
                    >
                        <span
                            v-if="
                                run.status === 'running' ||
                                run.status === 'pending'
                            "
                            class="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-[--binary-tertiary]"
                        />
                        <span
                            v-else
                            class="inline-block h-1.5 w-1.5 rounded-full bg-[--binary-primary]"
                        />
                        <span class="text-[--binary-text-muted]">{{
                            run.run_id
                        }}</span>
                        <span :class="statusClass(run.status)">{{
                            run.status
                        }}</span>
                        <span class="text-[--binary-text-muted] opacity-50">{{
                            run.created_at
                        }}</span>
                    </div>
                </div>
            </div>

            <!-- iframe -->
            <div
                class="min-h-0 flex-1 overflow-hidden rounded-none border border-[--binary-outline-variant] md:rounded-lg"
            >
                <iframe
                    ref="iframeRef"
                    :src="iframeSrc"
                    class="h-full w-full"
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

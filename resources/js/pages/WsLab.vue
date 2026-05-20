<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { api } from '@/lib/routes';
import { useAuth } from '@/composables/useAuth';

type DataPoint = { ts: number; value: number };
type CommandEntry = { user: string; text: string; ts: number };

const { user } = useAuth();

const MAX_POINTS = 80;
const CHART_W = 600;
const CHART_H = 200;

const wsStatus = ref<'offline' | 'connecting' | 'connected'>('offline');
const serverRunning = ref(false);
const streaming = ref(false);
const serverLoading = ref(false);
const streamLoading = ref(false);
const history = ref<DataPoint[]>([]);
const commands = ref<CommandEntry[]>([]);
const cmdInput = ref('');
const authToken = ref('');

const MAX_COMMANDS = 50;

let ws: WebSocket | null = null;
let heartbeatTimer: ReturnType<typeof setInterval> | null = null;

// ── WebSocket ────────────────────────────────────────────────────────────────

function connectWs() {
    if (ws) return;
    wsStatus.value = 'connecting';
    const proto = location.protocol === 'https:' ? 'wss' : 'ws';
    ws = new WebSocket(`${proto}://${location.host}/ws-lab`);

    ws.onopen = () => {
        wsStatus.value = 'connected';
        heartbeatTimer = setInterval(() => ws?.send(JSON.stringify({ type: 'ping' })), 10_000);
        if (authToken.value) {
            ws?.send(JSON.stringify({ type: 'auth', token: authToken.value }));
            authToken.value = '';
        }
    };

    ws.onmessage = (e) => {
        try {
            const msg = JSON.parse(e.data);
            if (msg.type === 'data') {
                history.value.push({ ts: msg.ts, value: msg.value });
                if (history.value.length > MAX_POINTS) history.value.shift();
            } else if (msg.type === 'command') {
                commands.value.push({ user: msg.user, text: msg.text, ts: Number(msg.ts) });
                if (commands.value.length > MAX_COMMANDS) commands.value.shift();
            }
        } catch { /* ignore */ }
    };

    ws.onclose = () => {
        wsStatus.value = 'offline';
        if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null; }
        ws = null;
    };

    ws.onerror = () => { ws?.close(); };
}

function disconnectWs() {
    if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null; }
    ws?.close();
    ws = null;
    wsStatus.value = 'offline';
}

// ── Server control (auth only) ────────────────────────────────────────────────

async function startServer() {
    serverLoading.value = true;
    try {
        const res = await fetch(api.wsLab.start(), { method: 'POST', credentials: 'include' });
        if (!res.ok) return;
        serverRunning.value = true;
        setTimeout(connectWs, 400);
    } finally {
        serverLoading.value = false;
    }
}

async function stopServer() {
    serverLoading.value = true;
    try {
        streaming.value = false;
        disconnectWs();
        await fetch(api.wsLab.stop(), { method: 'POST', credentials: 'include' });
        serverRunning.value = false;
    } finally {
        serverLoading.value = false;
    }
}

// ── Stream control (auth only) ────────────────────────────────────────────────

async function startStream() {
    streamLoading.value = true;
    try {
        const res = await fetch(api.wsLab.streamStart(), { method: 'POST', credentials: 'include' });
        if (!res.ok) return;
        streaming.value = true;
        if (wsStatus.value === 'offline') setTimeout(connectWs, 400);
    } finally {
        streamLoading.value = false;
    }
}

async function stopStream() {
    streamLoading.value = true;
    try {
        await fetch(api.wsLab.streamStop(), { method: 'POST', credentials: 'include' });
        streaming.value = false;
    } finally {
        streamLoading.value = false;
    }
}

// ── Command ───────────────────────────────────────────────────────────────────

function sendCommand() {
    const text = cmdInput.value.trim();
    if (!text || !ws || wsStatus.value !== 'connected') return;
    ws.send(JSON.stringify({ type: 'command', text, user: user.value?.name ?? 'unknown' }));
    cmdInput.value = '';
}

// ── Chart ────────────────────────────────────────────────────────────────────

function buildLinePath(): string {
    const pts = history.value;
    if (pts.length < 2) return '';
    const step = CHART_W / (MAX_POINTS - 1);
    const offset = MAX_POINTS - pts.length;
    return pts.map((p, i) => {
        const x = (offset + i) * step;
        const y = CHART_H - (p.value / 100) * CHART_H;
        return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
    }).join(' ');
}

function buildFillPath(): string {
    const pts = history.value;
    if (pts.length < 2) return '';
    const step = CHART_W / (MAX_POINTS - 1);
    const offset = MAX_POINTS - pts.length;
    const line = pts.map((p, i) => {
        const x = (offset + i) * step;
        const y = CHART_H - (p.value / 100) * CHART_H;
        return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
    }).join(' ');
    const lastX = ((offset + pts.length - 1) * step).toFixed(1);
    const firstX = (offset * step).toFixed(1);
    return `${line} L${lastX},${CHART_H} L${firstX},${CHART_H} Z`;
}

const linePath = computed(() => buildLinePath());
const fillPath = computed(() => buildFillPath());
const latestValue = computed(() => history.value.at(-1)?.value.toFixed(1) ?? null);

onMounted(async () => {
    try {
        const [statusRes, tokenRes] = await Promise.all([
            fetch(api.wsLab.status(), { credentials: 'include' }),
            user.value ? fetch(api.wsLab.authToken(), { method: 'POST', credentials: 'include' }) : Promise.resolve(null),
        ]);

        if (statusRes.ok) {
            const data = await statusRes.json();
            serverRunning.value = data.running;
        }

        if (tokenRes?.ok) {
            const data = await tokenRes.json();
            authToken.value = data.token;
        }

        if (serverRunning.value) connectWs();
    } catch { /* server offline, stay disconnected */ }
});
onUnmounted(() => disconnectWs());
</script>

<template>
    <Head title="ws-lab" />
    <AppLayout>
        <div class="flex flex-col gap-6 px-4 pb-8 pt-20 max-w-3xl mx-auto">

            <!-- Header -->
            <div class="flex items-center gap-3">
                <span class="font-mono text-sm text-[--binary-primary] tracking-widest uppercase">ws-lab</span>
                <span class="font-mono text-[10px] px-2 py-0.5 rounded-full border" :class="{
                    'border-[--binary-primary] text-[--binary-primary]': wsStatus === 'connected',
                    'border-[--binary-tertiary] text-[--binary-tertiary] animate-pulse': wsStatus === 'connecting',
                    'border-[--binary-outline-variant] text-[--binary-text-muted]': wsStatus === 'offline',
                }">{{ wsStatus }}</span>

                <div v-if="user" class="ml-auto flex items-center gap-2">
                    <!-- Server start / stop -->
                    <button
                        class="px-4 py-1.5 text-xs font-mono rounded border transition-colors disabled:opacity-40"
                        :class="serverRunning
                            ? 'border-[--binary-tertiary] text-[--binary-tertiary] hover:bg-[--binary-tertiary]/10'
                            : 'border-[--binary-outline-variant] text-[--binary-text-muted] hover:border-[--binary-primary] hover:text-[--binary-primary]'"
                        :disabled="serverLoading"
                        @click="serverRunning ? stopServer() : startServer()"
                    >{{ serverLoading ? '…' : serverRunning ? '■ server' : '▶ server' }}</button>

                    <!-- Stream start / stop -->
                    <button
                        class="px-4 py-1.5 text-xs font-mono rounded border transition-colors disabled:opacity-40"
                        :class="streaming
                            ? 'border-[--binary-tertiary] text-[--binary-tertiary] hover:bg-[--binary-tertiary]/10'
                            : 'border-[--binary-primary] text-[--binary-primary] hover:bg-[--binary-primary] hover:text-[--binary-on-primary-container]'"
                        :disabled="streamLoading || !serverRunning"
                        @click="streaming ? stopStream() : startStream()"
                    >{{ streamLoading ? '…' : streaming ? '■ stream' : '▶ stream' }}</button>
                </div>
            </div>

            <!-- Chart card -->
            <div class="rounded-lg border border-[--binary-outline-variant] bg-[--binary-surface-low] p-5">
                <p class="font-mono text-5xl font-bold text-[--binary-primary] mb-5 tabular-nums">
                    {{ latestValue ?? '--' }}
                </p>

                <svg :viewBox="`0 0 ${CHART_W} ${CHART_H}`" class="w-full" :height="CHART_H" preserveAspectRatio="none">
                    <defs>
                        <linearGradient id="ws-fill" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="var(--binary-primary)" stop-opacity="0.18" />
                            <stop offset="100%" stop-color="var(--binary-primary)" stop-opacity="0" />
                        </linearGradient>
                    </defs>

                    <!-- grid -->
                    <line v-for="n in [0.25, 0.5, 0.75]" :key="n"
                        x1="0" :y1="CHART_H * n" :x2="CHART_W" :y2="CHART_H * n"
                        stroke="rgba(165,209,180,0.06)" stroke-width="1" />

                    <template v-if="linePath">
                        <!-- fill -->
                        <path :d="fillPath" fill="url(#ws-fill)" />
                        <!-- line -->
                        <path :d="linePath"
                            fill="none"
                            stroke="var(--binary-primary)"
                            stroke-width="2"
                            stroke-linejoin="round"
                            stroke-linecap="round"
                        />
                    </template>

                    <text v-else
                        x="300" :y="CHART_H / 2 + 4"
                        text-anchor="middle"
                        font-family="monospace" font-size="12"
                        fill="rgba(165,209,180,0.2)">
                        {{ wsStatus === 'offline' ? 'offline — server not running' : 'waiting for stream…' }}
                    </text>
                </svg>
            </div>

            <!-- Command panel -->
            <div class="rounded-lg border border-[--binary-outline-variant] bg-[--binary-surface-low] p-5 flex flex-col gap-3">
                <span class="font-mono text-xs text-[--binary-text-muted] tracking-widest uppercase">command log</span>

                <!-- Log -->
                <div class="font-mono text-xs flex flex-col gap-1 min-h-[80px] max-h-48 overflow-y-auto">
                    <span v-if="commands.length === 0" class="text-[--binary-text-muted] opacity-40">no commands yet</span>
                    <div v-for="(cmd, i) in commands" :key="i" class="flex gap-2">
                        <span class="text-[--binary-tertiary] shrink-0">{{ cmd.user }}</span>
                        <span class="text-[--binary-text-muted]">&gt;</span>
                        <span class="text-[--binary-primary] break-all">{{ cmd.text }}</span>
                        <span class="text-[--binary-text-muted] ml-auto shrink-0 tabular-nums">
                            {{ new Date(cmd.ts).toLocaleTimeString() }}
                        </span>
                    </div>
                </div>

                <!-- Input (auth only) -->
                <form v-if="user" class="flex gap-2" @submit.prevent="sendCommand">
                    <span class="font-mono text-xs text-[--binary-tertiary] self-center">&gt;</span>
                    <input
                        v-model="cmdInput"
                        type="text"
                        placeholder="type a command…"
                        :disabled="wsStatus !== 'connected'"
                        class="flex-1 bg-transparent border-b border-[--binary-outline-variant] focus:border-[--binary-primary] outline-none font-mono text-xs text-[--binary-primary] placeholder:text-[--binary-text-muted] pb-0.5 disabled:opacity-40 transition-colors"
                    />
                    <button
                        type="submit"
                        :disabled="wsStatus !== 'connected' || !cmdInput.trim()"
                        class="font-mono text-xs px-3 py-1 rounded border border-[--binary-primary] text-[--binary-primary] hover:bg-[--binary-primary] hover:text-[--binary-on-primary-container] disabled:opacity-40 transition-colors"
                    >send</button>
                </form>
            </div>

        </div>
    </AppLayout>
</template>

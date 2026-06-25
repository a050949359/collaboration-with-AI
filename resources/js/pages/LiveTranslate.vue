<script setup lang="ts">
import { GoogleGenAI } from '@google/genai';
import { Head, usePage } from '@inertiajs/vue3';
import { ref, computed, onUnmounted } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { api } from '@/lib/routes';

const page = usePage();
const languages = computed<string[]>(
    () => (page.props.translateLanguages as string[]) ?? [],
);
const MODEL = String(
    page.props.liveModel || 'gemini-3.5-live-translate-preview',
);

const target = ref(languages.value[0] ?? 'English');
const state = ref<'idle' | 'connecting' | 'live' | 'error'>('idle');
const statusText = ref('');
const errMsg = ref('');

interface Line {
    who: 'src' | 'dst';
    text: string;
}
const lines = ref<Line[]>([]);

// 麥克風擷取的 AudioWorklet（16kHz mono → 2048 樣本一塊丟回主執行緒）。
const WORKLET_CODE = `
class MicProcessor extends AudioWorkletProcessor {
  constructor() { super(); this.buf = []; }
  process(inputs) {
    const ch = inputs[0] && inputs[0][0];
    if (ch) {
      for (let i = 0; i < ch.length; i++) this.buf.push(ch[i]);
      if (this.buf.length >= 2048) {
        this.port.postMessage(Float32Array.from(this.buf));
        this.buf = [];
      }
    }
    return true;
  }
}
registerProcessor('mic-processor', MicProcessor);
`;

let session: any = null;
let micCtx: AudioContext | null = null;
let playCtx: AudioContext | null = null;
let micStream: MediaStream | null = null;
let workletUrl = '';
let nextPlayTime = 0;

// 24kHz PCM(base64) → 排進播放佇列。
function playPcm(b64: string) {
    if (!playCtx) {
        return;
    }

    const bytes = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
    const i16 = new Int16Array(bytes.buffer);
    const f32 = Float32Array.from(i16, (v) => v / 32768);
    const buf = playCtx.createBuffer(1, f32.length, 24000);
    buf.getChannelData(0).set(f32);
    const src = playCtx.createBufferSource();
    src.buffer = buf;
    src.connect(playCtx.destination);
    const now = playCtx.currentTime;

    if (nextPlayTime < now) {
        nextPlayTime = now;
    }

    src.start(nextPlayTime);
    nextPlayTime += buf.duration;
}

async function start() {
    errMsg.value = '';
    lines.value = [];
    state.value = 'connecting';
    statusText.value = '鑄 token…';

    try {
        // 1) 後端鑄 ephemeral token（目標語言鎖在 server 端）。
        const res = await fetch(api.ai.liveToken(), {
            method: 'POST',
            credentials: 'include',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ target: target.value }),
        });

        if (!res.ok) {
            const j = await res.json().catch(() => ({}));

            throw new Error(j.message || `鑄 token 失敗（${res.status}）`);
        }

        const { token } = await res.json();

        // 2) 開麥克風（強制 16kHz，省去手動降採樣）。
        statusText.value = '開麥克風…';
        micStream = await navigator.mediaDevices.getUserMedia({
            audio: {
                channelCount: 1,
                echoCancellation: true,
                noiseSuppression: true,
            },
        });
        micCtx = new AudioContext({ sampleRate: 16000 });
        workletUrl = URL.createObjectURL(
            new Blob([WORKLET_CODE], { type: 'application/javascript' }),
        );
        await micCtx.audioWorklet.addModule(workletUrl);
        playCtx = new AudioContext({ sampleRate: 24000 });
        nextPlayTime = 0;

        // 3) 用 token 連 Live（apiKey 放 token，裸 key 不出後端）。
        statusText.value = '連線中…';
        const ai = new GoogleGenAI({
            apiKey: token,
            httpOptions: { apiVersion: 'v1alpha' },
        });
        session = await ai.live.connect({
            model: MODEL,
            callbacks: {
                onopen: () => {
                    state.value = 'live';
                    statusText.value = '🟢 連線中，開始說話吧';
                },
                onmessage: (m: any) => {
                    const sc = m.serverContent;
                    const audio = sc?.modelTurn?.parts?.find(
                        (p: any) => p.inlineData,
                    )?.inlineData?.data;

                    if (audio) {
                        playPcm(audio);
                    }

                    if (sc?.inputTranscription?.text) {
                        lines.value.push({
                            who: 'src',
                            text: sc.inputTranscription.text,
                        });
                    }

                    if (sc?.outputTranscription?.text) {
                        lines.value.push({
                            who: 'dst',
                            text: sc.outputTranscription.text,
                        });
                    }
                },
                onerror: (e: any) => {
                    errMsg.value = e?.message || '連線錯誤';
                    state.value = 'error';
                },
                onclose: () => {
                    if (state.value === 'live') {
                        statusText.value = '連線已關閉';
                    }
                },
            },
        });

        // 4) 麥克風 → session（worklet 回來的 Float32 轉 16-bit PCM 串上去）。
        const srcNode = micCtx.createMediaStreamSource(micStream);
        const node = new AudioWorkletNode(micCtx, 'mic-processor');
        node.port.onmessage = (e: MessageEvent) => {
            const f32 = e.data as Float32Array;
            const i16 = new Int16Array(f32.length);

            for (let i = 0; i < f32.length; i++) {
                const s = Math.max(-1, Math.min(1, f32[i]));
                i16[i] = s < 0 ? s * 32768 : s * 32767;
            }

            const b64 = btoa(
                String.fromCharCode(...new Uint8Array(i16.buffer)),
            );

            try {
                session?.sendRealtimeInput({
                    audio: { data: b64, mimeType: 'audio/pcm;rate=16000' },
                });
            } catch {
                // 連線已關閉時忽略。
            }
        };
        srcNode.connect(node);
    } catch (e: any) {
        errMsg.value = e?.message || String(e);
        state.value = 'error';
        statusText.value = '';
        cleanup();
    }
}

function cleanup() {
    try {
        session?.close();
    } catch {
        /* noop */
    }

    session = null;
    micStream?.getTracks().forEach((t) => t.stop());
    micStream = null;

    try {
        micCtx?.close();
    } catch {
        /* noop */
    }

    micCtx = null;

    try {
        playCtx?.close();
    } catch {
        /* noop */
    }

    playCtx = null;

    if (workletUrl) {
        URL.revokeObjectURL(workletUrl);
        workletUrl = '';
    }
}

function stop() {
    cleanup();

    if (state.value !== 'error') {
        state.value = 'idle';
        statusText.value = '';
    }
}

onUnmounted(cleanup);
</script>

<template>
    <Head title="即時語音翻譯" />
    <AppLayout>
        <div class="mx-auto w-full max-w-3xl px-[18px] pt-8 pb-16 md:px-8">
            <p
                class="binary-label mb-2 text-xs font-bold text-[var(--binary-primary)] uppercase"
            >
                &gt; live_translate
            </p>
            <h1 class="binary-page-title mb-8 text-[var(--binary-text)]">
                即時語音翻譯
            </h1>

            <div
                class="space-y-5 rounded-none border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-container)] p-5 md:rounded-2xl md:p-8"
            >
                <!-- 控制列 -->
                <div class="flex flex-wrap items-end gap-4">
                    <div class="space-y-1.5">
                        <label
                            class="binary-label block text-[11px] font-bold text-[var(--binary-outline)] uppercase"
                            >翻譯成（任意語言 → 此語言）</label
                        >
                        <select
                            v-model="target"
                            class="binary-input w-auto"
                            :disabled="state !== 'idle' && state !== 'error'"
                        >
                            <option v-for="l in languages" :key="l" :value="l">
                                {{ l }}
                            </option>
                        </select>
                    </div>

                    <button
                        v-if="state === 'idle' || state === 'error'"
                        class="binary-button"
                        @click="start"
                    >
                        ▶ 開始 <span aria-hidden="true">-></span>
                    </button>
                    <button
                        v-else
                        class="binary-ghost-button px-4 py-2"
                        @click="stop"
                    >
                        ⏹ 停止
                    </button>

                    <span
                        v-if="statusText"
                        class="text-xs text-[var(--binary-text-muted)]"
                        >{{ statusText }}</span
                    >
                </div>

                <p
                    v-if="errMsg"
                    class="border border-[var(--binary-tertiary)]/30 px-4 py-3 text-sm text-[var(--binary-tertiary)]"
                >
                    {{ errMsg }}
                </p>

                <!-- 逐字稿 / 翻譯 -->
                <div
                    class="min-h-[200px] space-y-2 rounded-none border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-high)] p-4 md:rounded-xl"
                >
                    <p
                        v-if="lines.length === 0"
                        class="text-sm text-[var(--binary-text-muted)]"
                    >
                        對著麥克風說話，譯文會即時念出來並顯示在這裡。
                    </p>
                    <p
                        v-for="(line, i) in lines"
                        :key="i"
                        class="text-sm"
                        :class="
                            line.who === 'dst'
                                ? 'text-[var(--binary-primary)]'
                                : 'text-[var(--binary-text-muted)]'
                        "
                    >
                        <span class="opacity-60">{{
                            line.who === 'dst' ? '🌐 ' : '🗣️ '
                        }}</span
                        >{{ line.text }}
                    </p>
                </div>

                <p class="text-[11px] text-[var(--binary-outline)]">
                    瀏覽器直接連 Gemini Live；目標語言鎖在後端鑄的 ephemeral
                    token，金鑰不出後端。需允許麥克風權限。
                </p>
            </div>
        </div>
    </AppLayout>
</template>

<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';

// ── WASM / script state ──────────────────────────────────
const wasmReady = ref(false);
const cameras = ref<MediaDeviceInfo[]>([]);
const selectedCam = ref('');
const started = ref(false);
const camError = ref('');

// 手機版底部控制 sheet 是否展開
const sheetOpen = ref(false);

// ── Processing params ────────────────────────────────────
const algorithm = ref(0); // 0=Canny 1=Laplacian 2=Sobel 3=Scharr
const t1 = ref(50);
const t2 = ref(150);
const aperture = ref(3);
const algKsize = ref(3);
const blurEnabled = ref(false);
const blurKsize = ref(5);
const invert = ref(false);
const overlay = ref(false);

const showCannyParams = computed(() => algorithm.value === 0);
const showKsizeParams = computed(
    () => algorithm.value === 1 || algorithm.value === 2,
);
const showScharrNote = computed(() => algorithm.value === 3);

// ── DOM refs ─────────────────────────────────────────────
const videoRef = ref<HTMLVideoElement | null>(null);
const canvasRef = ref<HTMLCanvasElement | null>(null);

let mod: any = null;
let stream: MediaStream | null = null;
let animFrame: number | null = null;

// ── Camera enumeration ───────────────────────────────────
async function enumerateCams() {
    try {
        const tmp = await navigator.mediaDevices.getUserMedia({ video: true });
        tmp.getTracks().forEach((t) => t.stop());
        const devices = await navigator.mediaDevices.enumerateDevices();
        cameras.value = devices.filter((d) => d.kind === 'videoinput');

        if (cameras.value.length) {
            // 桌機：預選第一個相機（維持原「選 + 開始」流程）
            // 手機：留空，走 placeholder + change 直接啟動
            if (!window.matchMedia('(max-width: 767px)').matches) {
                selectedCam.value = cameras.value[0].deviceId;
            }
        } else {
            camError.value = '找不到相機裝置';
        }
    } catch (e: any) {
        cameras.value = [];

        if (e?.name === 'NotFoundError' || e?.name === 'DevicesNotFoundError') {
            camError.value = '找不到相機裝置';
        } else if (
            e?.name === 'NotAllowedError' ||
            e?.name === 'PermissionDeniedError'
        ) {
            camError.value = '請允許存取相機';
        } else if (e?.name === 'NotReadableError') {
            camError.value = '相機裝置已被其他程式佔用';
        } else {
            camError.value = '無法存取相機';
        }
    }
}

// ── Start camera ─────────────────────────────────────────
async function startCamera() {
    if (!mod || !canvasRef.value || !videoRef.value) {
        return;
    }

    stream = await navigator.mediaDevices.getUserMedia({
        video: { deviceId: { exact: selectedCam.value } },
    });
    const video = videoRef.value;
    const canvas = canvasRef.value;
    video.srcObject = stream;
    await video.play();
    const scale = Math.min(1, 640 / video.videoWidth);
    canvas.width = Math.round(video.videoWidth * scale);
    canvas.height = Math.round(video.videoHeight * scale);
    started.value = true;
    renderLoop();
}

// ── Render loop ───────────────────────────────────────────
function renderLoop() {
    const video = videoRef.value;
    const canvas = canvasRef.value;

    if (!video || !canvas || !mod) {
        return;
    }

    const ctx = canvas.getContext('2d', { willReadFrequently: true })!;
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    const frame = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const buf = mod._malloc(frame.data.length);
    mod.HEAPU8.set(frame.data, buf);
    mod.detectEdges(
        buf,
        canvas.width,
        canvas.height,
        algorithm.value,
        t1.value,
        t2.value,
        algorithm.value === 0 ? aperture.value : algKsize.value,
        blurEnabled.value ? blurKsize.value : 0,
        invert.value,
        overlay.value,
    );
    frame.data.set(mod.HEAPU8.subarray(buf, buf + frame.data.length));
    mod._free(buf);
    ctx.putImageData(frame, 0, 0);
    animFrame = requestAnimationFrame(renderLoop);
}

// ── Lifecycle ─────────────────────────────────────────────
onMounted(() => {
    (window as any).Module = {
        onRuntimeInitialized() {
            mod = (window as any).Module;
            wasmReady.value = true;
            enumerateCams();
        },
    };
    const script = document.createElement('script');
    script.src = '/lab/cv/edge.js';
    script.async = true;
    document.body.appendChild(script);
});

onUnmounted(() => {
    if (animFrame !== null) {
        cancelAnimationFrame(animFrame);
    }

    if (stream) {
        stream.getTracks().forEach((t) => t.stop());
    }

    delete (window as any).Module;
    document.querySelector('script[src="/lab/cv/edge.js"]')?.remove();
});
</script>

<template>
    <AppLayout>
        <Head title="Computer Vision Lab" />

        <div
            class="flex min-h-screen flex-col bg-[var(--binary-background)] px-[18px] pt-8 pb-16 text-[var(--binary-text)] md:px-8"
        >
            <!-- Header（手機隱藏，全螢幕相機體驗） -->
            <div class="mb-8 hidden md:block">
                <p
                    class="mb-2 text-xs font-bold tracking-widest uppercase"
                    style="color: var(--binary-primary)"
                >
                    Computer Vision Lab
                </p>
                <h2
                    class="text-3xl font-bold md:text-4xl"
                    style="font-family: 'Space Grotesk', sans-serif"
                >
                    邊緣偵測
                </h2>
            </div>

            <!-- Loading -->
            <div v-if="!wasmReady" class="animate-pulse">
                <span
                    class="text-xs font-bold tracking-widest uppercase"
                    style="color: var(--binary-primary)"
                    >— 載入 WASM 模組 —</span
                >
            </div>

            <!-- Camera select (before start) -->
            <div
                v-if="wasmReady && !started"
                class="flex w-full max-w-sm flex-col gap-3"
            >
                <!-- 手機：選相機即啟動（placeholder 確保選任一個都觸發、免按開始） -->
                <select
                    v-model="selectedCam"
                    class="w-full rounded-md px-4 py-3 text-sm md:hidden"
                    style="
                        background: var(--binary-surface-low);
                        color: var(--binary-text);
                        outline: none;
                    "
                    @change="startCamera"
                >
                    <option value="" disabled>
                        {{ cameras.length ? '— 選擇相機 —' : '— 無相機 —' }}
                    </option>
                    <option
                        v-for="cam in cameras"
                        :key="cam.deviceId"
                        :value="cam.deviceId"
                    >
                        {{ cam.label || 'Camera' }}
                    </option>
                </select>

                <!-- 桌機：原本的「選相機 + 開始」流程 -->
                <div class="hidden items-center gap-4 md:flex">
                    <select
                        v-model="selectedCam"
                        class="flex-1 rounded-md px-4 py-3 text-sm"
                        style="
                            background: var(--binary-surface-low);
                            color: var(--binary-text);
                            outline: none;
                        "
                    >
                        <option v-if="!cameras.length" value="">
                            — 無相機 —
                        </option>
                        <option
                            v-for="cam in cameras"
                            :key="cam.deviceId"
                            :value="cam.deviceId"
                        >
                            {{ cam.label || 'Camera' }}
                        </option>
                    </select>
                    <button
                        :disabled="!cameras.length"
                        class="shrink-0 rounded-md px-6 py-3 text-sm font-bold tracking-wide transition-all disabled:cursor-not-allowed disabled:opacity-40"
                        style="
                            background: linear-gradient(
                                145deg,
                                var(--binary-primary),
                                var(--binary-primary-container)
                            );
                            color: var(--binary-on-primary-container);
                        "
                        @click="startCamera"
                    >
                        開始
                    </button>
                </div>
                <p
                    v-if="camError"
                    class="text-xs font-bold tracking-wide"
                    style="color: var(--binary-tertiary)"
                >
                    {{ camError }}
                </p>
            </div>

            <!-- Left / Right split (always in DOM, shown after start) -->
            <!-- 手機：fixed 全螢幕（脫離容器 padding）；桌機：原本的左右排版 -->
            <div
                v-show="started"
                class="fixed inset-x-0 top-16 bottom-0 z-30 bg-[var(--binary-background)] md:static md:z-auto md:flex md:flex-1 md:flex-row md:items-start md:gap-6 md:bg-transparent"
            >
                <!-- Left: canvas（手機滿版固定，sheet 覆蓋其上） -->
                <div class="h-full w-full md:h-auto md:min-w-0 md:flex-1">
                    <canvas
                        ref="canvasRef"
                        class="block h-full w-full object-cover md:h-auto md:rounded-xl md:object-contain"
                        style="
                            box-shadow: 0 0 40px
                                color-mix(
                                    in srgb,
                                    var(--binary-primary) 7%,
                                    transparent
                                );
                        "
                    />
                </div>

                <!-- Right: control panel（手機底部 overlay sheet；桌機側欄） -->
                <div
                    class="absolute inset-x-0 bottom-0 z-10 flex flex-col overflow-hidden rounded-t-2xl border-t border-[var(--binary-outline-variant)] transition-[height] duration-300 md:static md:z-auto md:h-auto md:w-72 md:overflow-visible md:rounded-xl md:border-t-0"
                    :class="sheetOpen ? 'h-[62dvh]' : 'h-10'"
                    style="
                        background: var(--binary-surface);
                        backdrop-filter: blur(20px);
                        -webkit-backdrop-filter: blur(20px);
                    "
                >
                    <!-- 手機 sheet handle（收合時只剩此條，點擊展開/收合） -->
                    <button
                        type="button"
                        class="flex h-10 w-full shrink-0 items-center justify-center md:hidden"
                        :aria-label="sheetOpen ? '收合控制板' : '展開控制板'"
                        @click="sheetOpen = !sheetOpen"
                    >
                        <span
                            class="h-1.5 w-10 rounded-full bg-[var(--binary-outline)]/50"
                        ></span>
                    </button>

                    <!-- 控制項：展開時可捲動；桌機正常顯示 -->
                    <div
                        class="flex flex-1 flex-col gap-4 overflow-y-auto px-4 pb-4 md:gap-6 md:overflow-visible md:p-6"
                    >
                        <!-- Algorithm -->
                        <div class="flex flex-col gap-3">
                            <span
                                class="text-xs font-bold tracking-widest uppercase"
                                style="color: var(--binary-primary)"
                                >算法</span
                            >
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="(alg, i) in [
                                        'Canny',
                                        'Laplacian',
                                        'Sobel',
                                        'Scharr',
                                    ]"
                                    :key="i"
                                    class="rounded-full px-3 py-1.5 text-xs font-bold tracking-wide transition-all"
                                    :style="
                                        algorithm === i
                                            ? 'background:linear-gradient(145deg,var(--binary-primary),var(--binary-primary-container)); color:var(--binary-on-primary-container);'
                                            : 'background:var(--binary-surface-container); color:var(--binary-text-muted);'
                                    "
                                    @click="algorithm = i"
                                >
                                    {{ alg }}
                                </button>
                            </div>
                        </div>

                        <!-- Canny params -->
                        <template v-if="showCannyParams">
                            <div
                                class="flex flex-col gap-4 rounded-lg px-4 py-4"
                                style="background: var(--binary-surface-lowest)"
                            >
                                <div class="flex items-center gap-3">
                                    <span
                                        class="w-14 shrink-0 text-xs font-bold tracking-widest uppercase"
                                        style="color: var(--binary-text-muted)"
                                        >低閾值</span
                                    >
                                    <input
                                        type="range"
                                        min="0"
                                        max="300"
                                        v-model.number="t1"
                                        class="flex-1"
                                        style="
                                            accent-color: var(--binary-primary);
                                        "
                                    />
                                    <span
                                        class="w-7 text-right text-xs tabular-nums"
                                        >{{ t1 }}</span
                                    >
                                </div>
                                <div class="flex items-center gap-3">
                                    <span
                                        class="w-14 shrink-0 text-xs font-bold tracking-widest uppercase"
                                        style="color: var(--binary-text-muted)"
                                        >高閾值</span
                                    >
                                    <input
                                        type="range"
                                        min="0"
                                        max="300"
                                        v-model.number="t2"
                                        class="flex-1"
                                        style="
                                            accent-color: var(--binary-primary);
                                        "
                                    />
                                    <span
                                        class="w-7 text-right text-xs tabular-nums"
                                        >{{ t2 }}</span
                                    >
                                </div>
                                <div class="flex items-center gap-3">
                                    <span
                                        class="w-14 shrink-0 text-xs font-bold tracking-widest uppercase"
                                        style="color: var(--binary-text-muted)"
                                        >孔徑</span
                                    >
                                    <select
                                        v-model.number="aperture"
                                        class="rounded px-2 py-1 text-xs"
                                        style="
                                            background: var(
                                                --binary-surface-low
                                            );
                                            color: var(--binary-text);
                                            outline: none;
                                        "
                                    >
                                        <option :value="3">3</option>
                                        <option :value="5">5</option>
                                        <option :value="7">7</option>
                                    </select>
                                </div>
                            </div>
                        </template>

                        <!-- Laplacian / Sobel ksize -->
                        <template v-if="showKsizeParams">
                            <div
                                class="flex items-center gap-3 rounded-lg px-4 py-4"
                                style="background: var(--binary-surface-lowest)"
                            >
                                <span
                                    class="w-14 shrink-0 text-xs font-bold tracking-widest uppercase"
                                    style="color: var(--binary-text-muted)"
                                    >核大小</span
                                >
                                <select
                                    v-model.number="algKsize"
                                    class="rounded px-2 py-1 text-xs"
                                    style="
                                        background: var(--binary-surface-low);
                                        color: var(--binary-text);
                                        outline: none;
                                    "
                                >
                                    <option :value="1">1</option>
                                    <option :value="3">3</option>
                                    <option :value="5">5</option>
                                    <option :value="7">7</option>
                                </select>
                            </div>
                        </template>

                        <!-- Scharr note -->
                        <p
                            v-if="showScharrNote"
                            class="text-xs tracking-wide"
                            style="color: var(--binary-text-muted)"
                        >
                            固定 3×3 Scharr 核，無需調整大小
                        </p>

                        <!-- Blur -->
                        <div class="flex flex-col gap-3">
                            <span
                                class="text-xs font-bold tracking-widest uppercase"
                                style="color: var(--binary-primary)"
                                >預模糊</span
                            >
                            <div
                                class="flex items-center gap-3 rounded-lg px-4 py-4"
                                style="background: var(--binary-surface-lowest)"
                            >
                                <button
                                    class="shrink-0 rounded-full px-3 py-1 text-xs font-bold tracking-wide transition-all"
                                    :style="
                                        blurEnabled
                                            ? 'background:linear-gradient(145deg,var(--binary-primary),var(--binary-primary-container)); color:var(--binary-on-primary-container);'
                                            : 'background:var(--binary-surface-container); color:var(--binary-text-muted);'
                                    "
                                    @click="blurEnabled = !blurEnabled"
                                >
                                    {{ blurEnabled ? '開' : '關' }}
                                </button>
                                <input
                                    type="range"
                                    min="3"
                                    max="21"
                                    step="2"
                                    v-model.number="blurKsize"
                                    :disabled="!blurEnabled"
                                    class="flex-1 disabled:opacity-30"
                                    style="accent-color: var(--binary-primary)"
                                />
                                <span
                                    class="w-7 text-right text-xs tabular-nums"
                                    >{{ blurKsize }}</span
                                >
                            </div>
                        </div>

                        <!-- Invert + Overlay -->
                        <div class="flex flex-col gap-3">
                            <span
                                class="text-xs font-bold tracking-widest uppercase"
                                style="color: var(--binary-primary)"
                                >輸出</span
                            >
                            <div class="flex flex-wrap gap-2">
                                <button
                                    class="rounded-full px-3 py-1.5 text-xs font-bold tracking-wide transition-all"
                                    :style="
                                        invert
                                            ? 'background:linear-gradient(145deg,var(--binary-primary),var(--binary-primary-container)); color:var(--binary-on-primary-container);'
                                            : 'background:var(--binary-surface-container); color:var(--binary-text-muted);'
                                    "
                                    @click="invert = !invert"
                                >
                                    反色
                                </button>
                                <button
                                    class="rounded-full px-3 py-1.5 text-xs font-bold tracking-wide transition-all"
                                    :style="
                                        overlay
                                            ? 'background:linear-gradient(145deg,var(--binary-primary),var(--binary-primary-container)); color:var(--binary-on-primary-container);'
                                            : 'background:var(--binary-surface-container); color:var(--binary-text-muted);'
                                    "
                                    @click="overlay = !overlay"
                                >
                                    疊加原圖
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Hidden video (frame source) -->
            <video ref="videoRef" autoplay playsinline class="hidden" />
        </div>
    </AppLayout>
</template>

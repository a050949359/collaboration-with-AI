<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';

const LW = 224;
const T_PRE = 0.5;
const T_GES = 0.6;

// 與 canned_gesture_classifier 輸出順序對齊（index 0 = Unknown）
const GESTURES = [
    { emoji: '❓', label: 'Unknown' },
    { emoji: '✊', label: 'Closed Fist' },
    { emoji: '🖐', label: 'Open Palm' },
    { emoji: '☝️', label: 'Pointing Up' },
    { emoji: '👎', label: 'Thumb Down' },
    { emoji: '👍', label: 'Thumb Up' },
    { emoji: '✌️', label: 'Victory' },
    { emoji: '🤟', label: 'I Love You' },
];

const BONE: [number, number][] = [
    [0, 1],
    [1, 2],
    [2, 3],
    [3, 4],
    [0, 5],
    [5, 6],
    [6, 7],
    [7, 8],
    [0, 9],
    [9, 10],
    [10, 11],
    [11, 12],
    [0, 13],
    [13, 14],
    [14, 15],
    [15, 16],
    [0, 17],
    [17, 18],
    [18, 19],
    [19, 20],
    [5, 9],
    [9, 13],
    [13, 17],
];

const modelsReady = ref(false);
const cameras = ref<MediaDeviceInfo[]>([]);
const selectedCam = ref('');
const started = ref(false);
const camError = ref('');
const sheetOpen = ref(false);
const statusText = ref('載入 WASM 模組…');
const currentGesture = ref(-1); // -1 未確定，1-7 手勢
const handPresent = ref(false); // presence >= T_PRE
const probsArr = ref<number[]>([]);

const videoRef = ref<HTMLVideoElement | null>(null);
const canvasRef = ref<HTMLCanvasElement | null>(null);

let mod: any = null;
let stream: MediaStream | null = null;
let animFrame: number | null = null;
const H = { lm: 0, emb: 0, cls: 0 };
let rgbaBufPtr = 0;
let cropSX = 0,
    cropSY = 0,
    cropSide = LW;
// 追蹤手部 crop region；null 時 fallback 到中心正方形
let activeCrop: { sx: number; sy: number; side: number } | null = null;
let lmCv: HTMLCanvasElement | null = null;
let lmCtx: CanvasRenderingContext2D | null = null;

// world landmarks（公尺單位）wrist-centred + unit-sphere 正規化
// embedder 吃 world landmarks（output 3），不是 image landmarks
function normWorld(wld: Float32Array): Float32Array {
    const n = new Float32Array(63);
    const wx = wld[0],
        wy = wld[1],
        wz = wld[2];

    for (let i = 0; i < 21; i++) {
        n[i * 3] = wld[i * 3] - wx;
        n[i * 3 + 1] = wld[i * 3 + 1] - wy;
        n[i * 3 + 2] = wld[i * 3 + 2] - wz;
    }

    let d = 0;

    for (let i = 0; i < 21; i++) {
        d = Math.max(d, Math.hypot(n[i * 3], n[i * 3 + 1], n[i * 3 + 2]));
    }

    if (d > 0) {
        for (let i = 0; i < 63; i++) {
            n[i] /= d;
        }
    }

    return n;
}

// 根據偵測到的手部 landmarks 計算下一幀的 crop region
// 2.5× padding + EMA 平滑（α=0.6）避免 crop 跳動
function computeCrop(
    rawLm: Float32Array,
    curCrop: { sx: number; sy: number; side: number },
    videoW: number,
    videoH: number,
): { sx: number; sy: number; side: number } {
    let x0 = Infinity,
        x1 = -Infinity,
        y0 = Infinity,
        y1 = -Infinity;

    for (let i = 0; i < 21; i++) {
        const px = (rawLm[i * 3] / LW) * curCrop.side + curCrop.sx;
        const py = (rawLm[i * 3 + 1] / LW) * curCrop.side + curCrop.sy;
        x0 = Math.min(x0, px);
        x1 = Math.max(x1, px);
        y0 = Math.min(y0, py);
        y1 = Math.max(y1, py);
    }

    const rawSide = Math.max(x1 - x0, y1 - y0) * 2.5;
    const cx = (x0 + x1) / 2,
        cy = (y0 + y1) / 2;
    const prev = activeCrop;
    const smCx = prev ? 0.6 * cx + 0.4 * (prev.sx + prev.side / 2) : cx;
    const smCy = prev ? 0.6 * cy + 0.4 * (prev.sy + prev.side / 2) : cy;
    const smSide = prev ? 0.6 * rawSide + 0.4 * prev.side : rawSide;
    const sx = Math.max(0, smCx - smSide / 2);
    const sy = Math.max(0, smCy - smSide / 2);

    return {
        sx: Math.round(sx),
        sy: Math.round(sy),
        side: Math.round(Math.min(smSide, videoW - sx, videoH - sy)),
    };
}

async function enumerateCams() {
    try {
        const tmp = await navigator.mediaDevices.getUserMedia({ video: true });
        tmp.getTracks().forEach((t) => t.stop());
        const devices = await navigator.mediaDevices.enumerateDevices();
        cameras.value = devices.filter((d) => d.kind === 'videoinput');

        if (cameras.value.length) {
            if (!window.matchMedia('(max-width: 767px)').matches) {
                selectedCam.value = cameras.value[0].deviceId;
            }
        } else {
            camError.value = '找不到相機裝置';
        }
    } catch (e: any) {
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

async function startCamera() {
    if (!mod || !canvasRef.value || !videoRef.value) {
        return;
    }

    try {
        stream = await navigator.mediaDevices.getUserMedia({
            video: { deviceId: { exact: selectedCam.value } },
        });
        const video = videoRef.value;
        video.srcObject = stream;
        await video.play();

        const canvas = canvasRef.value;
        canvas.width = video.videoWidth || 640;
        canvas.height = video.videoHeight || 480;

        cropSide = Math.min(video.videoWidth, video.videoHeight);
        cropSX = Math.floor((video.videoWidth - cropSide) / 2);
        cropSY = Math.floor((video.videoHeight - cropSide) / 2);

        started.value = true;
        renderLoop();
    } catch {
        camError.value = '無法啟動相機';
    }
}

function renderLoop() {
    animFrame = requestAnimationFrame(renderLoop);
    const video = videoRef.value;
    const canvas = canvasRef.value;

    if (!video || !canvas || !mod || !lmCtx || video.readyState < 2) {
        return;
    }

    const ctx = canvas.getContext('2d', { willReadFrequently: true })!;
    const vw = canvas.width;
    const vh = canvas.height;

    // 畫出影像（CSS scaleX(-1) 負責鏡像，這裡畫原始方向）
    ctx.drawImage(video, 0, 0, vw, vh);

    // 追蹤 crop：有偵測到手則用上一幀算出的 activeCrop，否則 fallback 中心正方形
    const curCrop = activeCrop ?? { sx: cropSX, sy: cropSY, side: cropSide };
    lmCtx.drawImage(
        video,
        curCrop.sx,
        curCrop.sy,
        curCrop.side,
        curCrop.side,
        0,
        0,
        LW,
        LW,
    );
    mod.HEAPU8.set(lmCtx.getImageData(0, 0, LW, LW).data, rgbaBufPtr);
    mod._rgba_to_input(H.lm, LW * LW);

    if (mod._tflite_run(H.lm) !== 1) {
        return;
    }

    const lmPtr = mod._tflite_output_ptr(H.lm, 0);
    const lmSize = mod._tflite_output_size(H.lm, 0);
    const rawLm = mod.HEAPF32.subarray(lmPtr >> 2, (lmPtr >> 2) + lmSize);
    const presence: number = mod.HEAPF32[mod._tflite_output_ptr(H.lm, 1) >> 2];

    if (presence < T_PRE) {
        activeCrop = null;
        currentGesture.value = -1;
        handPresent.value = false;
        probsArr.value = [];
        statusText.value = '沒偵測到手';

        return;
    }

    handPresent.value = true;
    // 計算下一幀 crop（EMA 平滑追蹤手部）
    activeCrop = computeCrop(
        rawLm,
        curCrop,
        video.videoWidth,
        video.videoHeight,
    );

    // 畫骨架（224 crop 座標 → curCrop → canvas）
    const pts: [number, number][] = [];

    for (let i = 0; i < 21; i++) {
        pts.push([
            (((rawLm[i * 3] / LW) * curCrop.side + curCrop.sx) /
                video.videoWidth) *
                vw,
            (((rawLm[i * 3 + 1] / LW) * curCrop.side + curCrop.sy) /
                video.videoHeight) *
                vh,
        ]);
    }

    ctx.strokeStyle = '#30d158';
    ctx.lineWidth = 2;

    for (const [a, b] of BONE) {
        ctx.beginPath();
        ctx.moveTo(pts[a][0], pts[a][1]);
        ctx.lineTo(pts[b][0], pts[b][1]);
        ctx.stroke();
    }

    for (let i = 0; i < 21; i++) {
        ctx.beginPath();
        ctx.arc(pts[i][0], pts[i][1], i === 0 ? 6 : 4, 0, Math.PI * 2);
        ctx.fillStyle = i === 0 ? '#ff9f0a' : '#30d158';
        ctx.fill();
    }

    // gesture_embedder：吃 world landmarks（output 3），wrist-centred + unit-sphere 正規化
    const wldPtr = mod._tflite_output_ptr(H.lm, 3);
    const wldSize = mod._tflite_output_size(H.lm, 3);
    const wld = mod.HEAPF32.subarray(wldPtr >> 2, (wldPtr >> 2) + wldSize);
    mod.HEAPF32.set(
        normWorld(new Float32Array(wld)),
        mod._tflite_input_ptr(H.emb) >> 2,
    );

    if (mod._tflite_run(H.emb) !== 1) {
        return;
    }

    // canned_gesture_classifier
    const embOutPtr = mod._tflite_output_ptr(H.emb, 0);
    const embOutSize = mod._tflite_output_size(H.emb, 0);
    mod.HEAPF32.set(
        mod.HEAPF32.subarray(embOutPtr >> 2, (embOutPtr >> 2) + embOutSize),
        mod._tflite_input_ptr(H.cls) >> 2,
    );

    if (mod._tflite_run(H.cls) !== 1) {
        return;
    }

    // classifier output 已是 softmax 機率，不可再做 softmax
    const clsOutPtr = mod._tflite_output_ptr(H.cls, 0);
    const clsOutSize = mod._tflite_output_size(H.cls, 0);
    const p = Array.from<number>(
        mod.HEAPF32.subarray(clsOutPtr >> 2, (clsOutPtr >> 2) + clsOutSize),
    );
    probsArr.value = p;
    const best = p.indexOf(Math.max(...p));
    currentGesture.value = p[best] >= T_GES && best > 0 ? best : -1;
    statusText.value = `${GESTURES[best].label} (${(p[best] * 100).toFixed(0)}%)`;
}

onMounted(() => {
    lmCv = document.createElement('canvas');
    lmCv.width = LW;
    lmCv.height = LW;
    lmCtx = lmCv.getContext('2d', { willReadFrequently: true })!;

    (window as any).Module = {
        onRuntimeInitialized: async function () {
            mod = (window as any).Module;
            statusText.value = '載入模型中…';

            try {
                async function loadModel(path: string): Promise<number> {
                    const data = new Uint8Array(
                        await (await fetch(path)).arrayBuffer(),
                    );
                    const ptr = mod._malloc(data.byteLength);
                    mod.HEAPU8.set(data, ptr);
                    const h = mod._tflite_create(ptr, data.byteLength, 1);
                    mod._free(ptr);

                    if (!h) {
                        throw new Error('無法載入 ' + path);
                    }

                    return h;
                }
                H.lm = await loadModel(
                    '/lab/cv/models/hand_landmarks_detector.tflite',
                );
                H.emb = await loadModel(
                    '/lab/cv/models/gesture_embedder.tflite',
                );
                H.cls = await loadModel(
                    '/lab/cv/models/canned_gesture_classifier.tflite',
                );
                rgbaBufPtr = mod._get_rgba_buf();
                modelsReady.value = true;
                statusText.value = '✅ 就緒';
                enumerateCams();
            } catch (e: any) {
                statusText.value = '❌ ' + e.message;
            }
        },
    };
    const script = document.createElement('script');
    script.src = '/lab/cv/gesture.js';
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
    document.querySelector('script[src="/lab/cv/gesture.js"]')?.remove();
});
</script>

<template>
    <AppLayout>
        <Head title="手勢辨識 — Gesture Recognition" />

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
                    手勢辨識
                </h2>
                <p
                    class="mt-3 max-w-xl text-sm leading-relaxed"
                    style="color: var(--binary-text-muted)"
                >
                    MediaPipe TFLite WASM 全幀手部關鍵點偵測，pipeline：
                    <span style="color: var(--binary-primary)"
                        >video → 224×224 → hand landmarks → embedder →
                        classifier</span
                    >。
                </p>
            </div>

            <!-- 載入中 -->
            <div v-if="!modelsReady" class="animate-pulse">
                <span
                    class="text-xs font-bold tracking-widest uppercase"
                    style="color: var(--binary-primary)"
                    >— {{ statusText }} —</span
                >
            </div>

            <!-- 選相機（啟動前） -->
            <div
                v-if="modelsReady && !started"
                class="flex w-full max-w-sm flex-col gap-3"
            >
                <!-- 手機：選相機即啟動 -->
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

                <!-- 桌機：選 + 開始 -->
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

            <!-- 相機畫面 + 控制面板 -->
            <div
                v-show="started"
                class="fixed inset-x-0 top-16 bottom-0 z-30 bg-[var(--binary-background)] md:static md:z-auto md:flex md:flex-1 md:flex-row md:items-start md:gap-6 md:bg-transparent"
            >
                <!-- 手機辨識結果浮層（右下角，sheet 收合時可見） -->
                <div
                    v-if="handPresent"
                    class="absolute right-4 bottom-12 z-20 flex flex-col items-center gap-0.5 md:hidden"
                >
                    <div
                        class="flex h-14 w-14 items-center justify-center rounded-2xl text-3xl shadow-lg transition-opacity duration-150"
                        :class="
                            currentGesture >= 1 ? 'opacity-100' : 'opacity-35'
                        "
                        style="
                            background: var(--binary-surface);
                            backdrop-filter: blur(12px);
                            -webkit-backdrop-filter: blur(12px);
                            border: 1px solid var(--binary-outline-variant);
                        "
                    >
                        {{
                            currentGesture >= 1
                                ? GESTURES[currentGesture].emoji
                                : '❓'
                        }}
                    </div>
                </div>

                <!-- Canvas（CSS 鏡像，骨架以原始座標畫，鏡像由 CSS 處理） -->
                <div class="h-full w-full md:h-auto md:w-[480px] md:shrink-0">
                    <canvas
                        ref="canvasRef"
                        class="block h-full w-full object-cover md:h-auto md:rounded-xl md:object-contain"
                        style="
                            transform: scaleX(-1);
                            box-shadow: 0 0 40px
                                color-mix(
                                    in srgb,
                                    var(--binary-primary) 7%,
                                    transparent
                                );
                        "
                    />
                </div>

                <!-- 控制面板（手機底部 sheet；桌機右側欄） -->
                <div
                    class="absolute inset-x-0 bottom-0 z-10 flex flex-col overflow-hidden rounded-t-2xl border-t border-[var(--binary-outline-variant)] transition-[height] duration-300 md:static md:z-auto md:h-auto md:w-72 md:overflow-visible md:rounded-xl md:border-t-0"
                    :class="sheetOpen ? 'h-[62dvh]' : 'h-10'"
                    style="
                        background: var(--binary-surface);
                        backdrop-filter: blur(20px);
                        -webkit-backdrop-filter: blur(20px);
                    "
                >
                    <!-- Sheet handle -->
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

                    <div
                        class="flex flex-1 flex-col gap-4 overflow-y-auto px-4 pb-4 md:gap-6 md:overflow-visible md:p-6"
                    >
                        <!-- 目前手勢 -->
                        <div class="flex flex-col items-center gap-2 py-2">
                            <span class="text-5xl" aria-hidden="true">{{
                                currentGesture >= 1
                                    ? GESTURES[currentGesture].emoji
                                    : '🖐'
                            }}</span>
                            <p
                                class="text-sm font-bold tracking-wide"
                                style="color: var(--binary-primary)"
                            >
                                {{
                                    currentGesture >= 1
                                        ? GESTURES[currentGesture].label
                                        : handPresent
                                          ? '—'
                                          : '等待偵測…'
                                }}
                            </p>
                        </div>

                        <!-- 信心度 bars -->
                        <div v-if="probsArr.length" class="flex flex-col gap-1">
                            <span
                                class="text-xs font-bold tracking-widest uppercase"
                                style="color: var(--binary-primary)"
                                >信心度</span
                            >
                            <div class="mt-2 flex flex-col gap-1.5">
                                <div
                                    v-for="(g, i) in GESTURES"
                                    :key="i"
                                    class="flex items-center gap-2"
                                >
                                    <span class="w-5 text-sm">{{
                                        g.emoji
                                    }}</span>
                                    <div
                                        class="relative h-2 flex-1 overflow-hidden rounded-full"
                                        style="
                                            background: var(
                                                --binary-surface-lowest
                                            );
                                        "
                                    >
                                        <div
                                            class="h-full rounded-full transition-all duration-100"
                                            :style="{
                                                width:
                                                    (probsArr[i] * 100).toFixed(
                                                        1,
                                                    ) + '%',
                                                background:
                                                    i === currentGesture
                                                        ? 'var(--binary-primary)'
                                                        : 'var(--binary-outline)',
                                            }"
                                        />
                                    </div>
                                    <span
                                        class="w-9 text-right text-xs tabular-nums"
                                        style="color: var(--binary-text-muted)"
                                        >{{
                                            (probsArr[i] * 100).toFixed(0) + '%'
                                        }}</span
                                    >
                                </div>
                            </div>
                        </div>

                        <!-- 手勢圖例 -->
                        <div>
                            <span
                                class="text-xs font-bold tracking-widest uppercase"
                                style="color: var(--binary-primary)"
                                >可辨識手勢</span
                            >
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <div
                                    v-for="g in GESTURES.slice(1)"
                                    :key="g.label"
                                    class="flex items-center gap-2 rounded-lg px-3 py-2.5"
                                    style="
                                        background: var(
                                            --binary-surface-container
                                        );
                                    "
                                >
                                    <span class="text-lg" aria-hidden="true">{{
                                        g.emoji
                                    }}</span>
                                    <span
                                        class="text-xs tracking-wide"
                                        style="color: var(--binary-text-muted)"
                                        >{{ g.label }}</span
                                    >
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 隱藏 video（相機來源） -->
            <video ref="videoRef" autoplay playsinline muted class="hidden" />
        </div>
    </AppLayout>
</template>

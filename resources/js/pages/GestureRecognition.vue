<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';

// ─────────────────────────────────────────────────────────────
// 手勢辨識（MediaPipe TFLite WASM）
//
// ⚠️ 模型尚未部署，本頁暫時擋住實際使用（不請求相機、不載入 WASM）。
//
// 已就位：WASM runtime 已放在 public/lab/cv/（gesture.js + gesture.wasm）。
//         gesture.js 是標準 Emscripten 輸出，會自動從同目錄抓 gesture.wasm。
//
// 之後要啟用時：
//   1. 把 3 個模型放到 public/lab/cv/models/：
//        hand_landmarks_detector.tflite
//        gesture_embedder.tflite
//        canned_gesture_classifier.tflite
//   2. 把 MODELS_READY 改成 true，並接上 tmp/index.html 的 pipeline 邏輯
//        （video → 224×224 → hand_landmarks → gesture_embedder → classifier）
//      注入 script.src = '/lab/cv/gesture.js'，模型用絕對路徑 /lab/cv/models/*.tflite
//   3. 手機版相機流程「比照邊緣偵測頁」(ComputerVision.vue)：
//        - 手機隱藏標題 (hidden md:block)，走全螢幕相機體驗
//        - 手機：選相機即啟動（placeholder option + @change，免按「開始」）
//        - 桌機：維持「選相機 + 開始」
//        - 相機 canvas 手機 fixed inset-x-0 top-16 bottom-0 滿版
//        - 控制面板（手勢圖例 + 即時信心度 bar）走底部 overlay sheet，桌機則右側欄
// ─────────────────────────────────────────────────────────────
const MODELS_READY = false;

// 可辨識的 8 種手勢（與 canned_gesture_classifier 對齊）
const GESTURES: { emoji: string; label: string }[] = [
    { emoji: '✊', label: 'Closed Fist' },
    { emoji: '🖐', label: 'Open Palm' },
    { emoji: '☝️', label: 'Pointing Up' },
    { emoji: '👎', label: 'Thumb Down' },
    { emoji: '👍', label: 'Thumb Up' },
    { emoji: '✌️', label: 'Victory' },
    { emoji: '🤟', label: 'I Love You' },
    { emoji: '❓', label: 'Unknown' },
];
</script>

<template>
    <AppLayout>
        <Head title="手勢辨識 — Gesture Recognition" />

        <div
            class="flex min-h-screen flex-col bg-[var(--binary-background)] px-[18px] pt-8 pb-16 text-[var(--binary-text)] md:px-8"
        >
            <!-- Header -->
            <div class="mb-8">
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

            <div class="flex flex-1 flex-col gap-6 md:flex-row md:items-start">
                <!-- Camera / preview area -->
                <div class="md:min-w-0 md:flex-1">
                    <div
                        class="relative flex aspect-[4/3] w-full items-center justify-center overflow-hidden rounded-xl border border-[var(--binary-outline-variant)]"
                        style="background: var(--binary-surface-lowest)"
                    >
                        <!-- 模型部署中遮罩 -->
                        <div
                            class="flex flex-col items-center gap-4 px-6 text-center"
                        >
                            <span
                                class="text-5xl opacity-40 grayscale"
                                aria-hidden="true"
                                >🖐</span
                            >
                            <p
                                class="text-xs font-bold tracking-widest uppercase"
                                style="color: var(--binary-primary)"
                            >
                                — 模型部署中 —
                            </p>
                            <p
                                class="max-w-xs text-sm leading-relaxed"
                                style="color: var(--binary-text-muted)"
                            >
                                WASM 模組與 TFLite
                                模型尚未上線，手勢辨識暫不開放。
                            </p>
                        </div>
                    </div>

                    <button
                        type="button"
                        disabled
                        class="mt-4 w-full rounded-md px-6 py-3 text-sm font-bold tracking-wide transition-all disabled:cursor-not-allowed disabled:opacity-40 md:w-auto"
                        style="
                            background: linear-gradient(
                                145deg,
                                var(--binary-primary),
                                var(--binary-primary-container)
                            );
                            color: var(--binary-on-primary-container);
                        "
                    >
                        {{ MODELS_READY ? '開始' : '尚未開放' }}
                    </button>
                </div>

                <!-- Gesture legend -->
                <div class="md:w-72 md:shrink-0">
                    <span
                        class="text-xs font-bold tracking-widest uppercase"
                        style="color: var(--binary-primary)"
                        >可辨識手勢</span
                    >
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <div
                            v-for="g in GESTURES"
                            :key="g.label"
                            class="flex items-center gap-2 rounded-lg px-3 py-2.5"
                            style="background: var(--binary-surface-container)"
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
    </AppLayout>
</template>

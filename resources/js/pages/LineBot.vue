<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
</script>

<template>
    <Head title="LineBot" />

    <AppLayout>
        <main class="pt-24 pb-24">
            <section class="mx-auto max-w-screen-xl px-6 py-12 md:px-8">
                <div class="binary-card-raised rounded-2xl">
                    <span
                        class="binary-label mb-3 block text-xs font-bold text-[var(--binary-primary)] uppercase"
                    >
                        &gt; linebot_entry
                    </span>
                    <h1
                        class="binary-display mb-4 text-4xl font-black tracking-tight uppercase md:text-5xl"
                    >
                        LineBot Entry
                    </h1>
                    <p
                        class="mb-6 text-sm leading-relaxed text-[var(--binary-text-muted)]"
                    >
                        這裡是 LineBot 加好友入口頁。QRCode
                        圖片可稍後補上，目前先提供按鈕入口與互動說明。
                    </p>

                    <div
                        class="mb-6 rounded-xl border border-[var(--binary-outline)]/30 bg-[var(--binary-surface-container)] p-4"
                    >
                        <p
                            class="binary-label mb-2 text-xs text-[var(--binary-outline)] uppercase"
                        >
                            step 01
                        </p>
                        <p class="text-sm text-[var(--binary-text)]">
                            先把你的 LINE 加好友連結貼到此按鈕
                            href，使用者即可直接加好友。
                        </p>
                    </div>
                </div>
            </section>
        </main>
    </AppLayout>
</template>

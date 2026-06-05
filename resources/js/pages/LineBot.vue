<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
</script>

<template>
    <Head title="LineBot" />

    <AppLayout>
        <main class="pb-24">
            <section class="binary-section mx-auto max-w-screen-xl">
                <span
                    class="binary-label mb-3 block text-xs font-bold text-[var(--binary-primary)] uppercase"
                >
                    &gt; linebot_entry
                </span>
                <h1 class="binary-page-title mb-4">LineBot Entry</h1>
                <p
                    class="text-sm leading-relaxed text-[var(--binary-text-muted)]"
                >
                    這裡是 LineBot 加好友入口頁。QRCode
                    圖片可稍後補上，目前先提供按鈕入口與互動說明。
                </p>
            </section>
        </main>
    </AppLayout>
</template>

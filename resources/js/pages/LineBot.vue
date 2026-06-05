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
                    加入官方帳號好友即可使用 LineBot
                    服務。手機點下方按鈕直接加入，電腦可掃描 QR code。
                </p>

                <!-- 手機：點按鈕喚起 LINE 加好友 -->
                <a
                    href="https://line.me/R/ti/p/@569gqteq"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="binary-button mt-8 w-full max-w-xs md:hidden"
                >
                    加入好友
                    <span aria-hidden="true">-></span>
                </a>

                <!-- 桌機：QR 給其他手機掃 -->
                <div class="mt-8 hidden md:block">
                    <img
                        src="/images/linebot/qr.png"
                        alt="LINE 加好友 QR code"
                        width="360"
                        height="360"
                        class="w-44 max-w-full rounded-lg bg-white p-2"
                    />
                    <p
                        class="binary-label mt-3 text-xs text-[var(--binary-outline)] uppercase"
                    >
                        &gt; scan_to_add_friend
                    </p>
                </div>
            </section>
        </main>
    </AppLayout>
</template>

<script setup lang="ts">
defineProps<{
    stackInfo: [string, string][];
}>();
</script>

<template>
    <section id="about" class="mx-auto grid max-w-screen-2xl grid-cols-1 gap-8 px-6 py-20 md:grid-cols-12 md:py-32 md:px-8">
        <div class="md:col-span-8">
            <div class="mb-6 inline-block rounded-full bg-[var(--binary-surface-container)] px-4 py-2">
                <span class="binary-label text-xs font-bold uppercase text-[var(--binary-primary)]">&gt; architect_mode</span>
            </div>
            <h1 class="binary-display text-5xl font-black leading-[0.9] tracking-tight md:text-8xl">
                SERIOUS
                <br>
                <span class="text-[var(--binary-primary)]">BACKEND</span>
                <br>
                SYSTEMS
            </h1>
            <p class="mt-8 max-w-2xl text-xl leading-relaxed text-[var(--binary-text-muted)] md:text-2xl">
                以深色調、寬呼吸感與不對稱編排，展示偏成熟工程團隊的工作室氣質。不是編輯器風格，而是工程架構作品集。
            </p>
        </div>

        <div class="flex flex-col justify-end md:col-span-4">
            <div class="binary-card-raised rounded-[1.5rem]">
                <div class="mb-4 flex items-end justify-between">
                    <span class="binary-label text-[10px] uppercase text-[var(--binary-outline)]">stack.info</span>
                    <span class="text-xl text-[var(--binary-primary)]">&gt;_</span>
                </div>
                <div class="space-y-2 binary-label text-sm">
                    <div
                        v-for="([label, value], index) in stackInfo"
                        :key="label"
                        class="flex justify-between pb-2"
                        :class="index !== stackInfo.length - 1 ? 'bg-[rgba(15,21,17,0.24)] px-3 py-2 rounded-xl' : 'bg-[rgba(15,21,17,0.24)] px-3 py-2 rounded-xl'"
                    >
                        <span class="text-[var(--binary-text-muted)]">{{ label }}</span>
                        <span class="text-[var(--binary-primary)]">{{ value }}</span>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

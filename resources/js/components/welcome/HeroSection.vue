<script setup lang="ts">
defineProps<{
    stackInfo: [string, string][];
}>();
</script>

<template>
    <section id="about" class="mx-auto grid h-screen max-w-screen-2xl grid-cols-1 items-center gap-8 px-6 pt-24 pb-60 md:grid-cols-12 md:px-8">
        <div class="md:col-start-2 md:col-span-6">
            <h1 class="binary-display text-5xl font-black leading-[0.9] tracking-tight md:text-8xl">
                SYSTEM
                <br>
                <span class="text-[var(--binary-primary)]">ARCHITECT</span>
            </h1>
            <p class="mt-8 max-w-2xl text-xl leading-relaxed text-[var(--binary-text-muted)] md:text-2xl">
                一個軟體工程師的開發日誌。與 AI 協同、拿 Laravel 打底，思考把已具備的技術變成跑在 server 上展示的東西。不讓 AI 全包，而是知道哪裡該放手、哪裡要自己來。
                <span class="ml-1 binary-label text-xs text-[var(--binary-outline)]">by LLM</span>
            </p>
        </div>

        <div class="flex flex-col justify-center md:col-span-4">
            <div class="binary-card-raised rounded-[1.5rem]">
                <div class="mb-4 flex items-end justify-between">
                    <div class="flex items-center gap-1">
                        <span class="text-sm text-[var(--binary-primary)]">&gt;_</span>
                        <span class="terminal-typed binary-label text-[10px] uppercase text-[var(--binary-outline)]">stack.info</span><span class="terminal-caret text-[var(--binary-outline)]"></span>
                    </div>
                </div>
                <div class="space-y-2 binary-label text-sm">
                    <div
                        v-for="[label, value] in stackInfo"
                        :key="label"
                        class="flex flex-col gap-0.5 bg-[rgba(15,21,17,0.24)] px-3 py-2 rounded-xl"
                    >
                        <span class="text-[10px] text-[var(--binary-text-muted)] uppercase tracking-wider">{{ label }}</span>
                        <span class="text-[var(--binary-primary)] leading-snug">{{ value }}</span>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.terminal-typed {
    overflow: hidden;
    white-space: nowrap;
    width: 0;
    display: inline-block;
    vertical-align: middle;
    animation: type-out 0.7s steps(10) 0.4s forwards;
}

.terminal-caret {
    display: inline-block;
    width: 1px;
    height: 0.9em;
    background: currentColor;
    vertical-align: middle;
    opacity: 0;
    animation: caret-blink 0.9s steps(2) 1.1s infinite;
}

@keyframes type-out {
    to { width: 10ch; }
}

@keyframes caret-blink {
    0%   { opacity: 1; }
    100% { opacity: 1; }
    50%  { opacity: 0; }
}
</style>

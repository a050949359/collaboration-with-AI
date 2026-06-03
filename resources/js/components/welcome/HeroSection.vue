<script setup lang="ts">
import KnowledgeGraphWidget from './KnowledgeGraphWidget.vue';

const categories = [
    { label: 'Backend', items: ['Laravel 13', 'PHP 8.4', 'Go', 'Python'] },
    {
        label: 'Frontend',
        items: [
            'Vue 3',
            'TypeScript',
            'Inertia.js',
            'Tailwind CSS',
            'Vite',
            'Matter.js',
        ],
    },
    { label: 'Database', items: ['SQLite', 'Redis'] },
    { label: 'AI / LLM', items: ['Gemini', 'Vertex AI'] },
    {
        label: 'Infrastructure',
        items: [
            'Nginx',
            'Cloudflare',
            'Ansible',
            "Let's Encrypt",
            'SNMP',
            'GCP',
            'Oracle Cloud',
            'LightNode',
        ],
    },
    {
        label: 'Tooling',
        items: ['WebSocket', 'Flask', 'k6', 'Queue', 'Wikidata', 'D3'],
    },
];
</script>

<template>
    <section
        id="about"
        class="mx-auto grid max-w-screen-2xl grid-cols-1 gap-8 px-6 py-12 md:grid-cols-12 md:gap-8 md:px-8"
        style="min-height: calc(100vh - 4rem)"
    >
        <div
            class="flex min-h-0 flex-col md:col-span-6 md:col-start-2 md:h-full"
        >
            <h1
                class="binary-display text-5xl leading-[0.9] font-black tracking-tight md:text-8xl"
            >
                SYSTEM
                <br />
                <span class="text-[var(--binary-primary)]">ARCHITECT</span>
            </h1>
            <p
                class="mt-6 max-w-2xl text-xl leading-relaxed text-[var(--binary-text-muted)] md:text-2xl"
            >
                一個軟體工程師的開發日誌。與 AI 協同、拿 Laravel
                打底，思考把已具備的技術變成跑在 server 上展示的東西。不讓 AI
                全包，而是知道哪裡該放手、哪裡要自己來。
                <span
                    class="binary-label ml-1 text-xs text-[var(--binary-outline)]"
                    >by LLM</span
                >
            </p>

            <!-- Knowledge Graph Widget -->
            <div class="mt-8 flex min-h-0 flex-1 flex-col">
                <p
                    class="binary-label mb-2 shrink-0 text-[10px] font-bold text-[var(--binary-outline)] uppercase"
                >
                    &gt; PROJECT_RELATIONS
                </p>
                <div
                    class="min-h-0 flex-1 overflow-hidden rounded-2xl border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-lowest)]"
                >
                    <KnowledgeGraphWidget />
                </div>
            </div>
        </div>

        <div class="md:col-span-4 md:h-full">
            <div class="binary-card-raised h-full rounded-[1.5rem]">
                <div class="mb-4 flex items-center gap-1">
                    <span class="text-sm text-[var(--binary-primary)]"
                        >&gt;_</span
                    >
                    <span
                        class="terminal-typed binary-label text-[10px] text-[var(--binary-outline)] uppercase"
                        >This Project</span
                    >
                    <span
                        class="terminal-caret text-[var(--binary-outline)]"
                    ></span>
                </div>
                <div class="space-y-2">
                    <div
                        v-for="cat in categories"
                        :key="cat.label"
                        class="rounded-xl bg-[rgba(15,21,17,0.24)] px-3 py-2"
                    >
                        <span
                            class="binary-label mb-1.5 block text-[10px] tracking-wider text-[var(--binary-text-muted)] uppercase"
                            >{{ cat.label }}</span
                        >
                        <div class="flex flex-wrap gap-1.5">
                            <span
                                v-for="item in cat.items"
                                :key="item"
                                class="binary-chip binary-label text-[10px] uppercase"
                                >&gt; {{ item }}</span
                            >
                        </div>
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
    animation: type-out 0.7s steps(12) 0.4s forwards;
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
    to {
        width: 13ch;
    }
}

@keyframes caret-blink {
    0% {
        opacity: 1;
    }
    100% {
        opacity: 1;
    }
    50% {
        opacity: 0;
    }
}
</style>

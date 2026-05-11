<script setup lang="ts">
import { computed } from 'vue';

interface Commit {
    hash: string;
    date: string;
    message: string;
    tag?: string;
}

interface Project {
    id: string;
    category: string;
    title: string;
    description: string | string[];
    tags: string[];
    image?: string;
    commits?: Commit[];
}

const props = defineProps<{
    featuredProjects: Project[];
}>();

const airportProject: Project = {
    id: '03',
    category: 'AVIATION_PLATFORM',
    title: 'Global Aviation & Geo Intelligence',
    description: [
        '機場：洲別／類型統計儀表板，D3 互動地球視圖，可點擊國家即時查詢並標示大頭針。',
        '航空公司 ＆ 國家：透過 Wikidata SPARQL 批次匯入，補全中文名稱與 ISO 代碼。',
        '城市：即時搜尋 Wikidata 候選城市，確認後以非同步 Job 寫入；查詢 API 加上每分鐘限流控制。',
    ],
    tags: ['Airports', 'Airlines', 'Countries', 'Cities', 'Wikidata', 'D3 Globe', 'Queue', 'Rate Limit'],
    image: '/images/projects/project03.webp',
};

const lineAutomationProject: Project = {
    id: '04',
    category: 'LINE_AUTOMATION',
    title: 'LINE Article Automation',
    description:
        '整合 LINE Bot 與 Laravel 內部 API，支援好友綁定、快速產文、完成通知 webhook，並加上 API key 與 HMAC 驗證，確保跨主機傳輸安全與流程可追蹤。',
    tags: ['LINE Messaging API', 'Webhook', 'HMAC', 'Queue'],
    image: '/images/projects/project04.webp',
};

const projects = computed(() => [...props.featuredProjects, airportProject, lineAutomationProject]);
</script>

<template>
    <section id="projects" class="bg-[var(--binary-surface)] px-6 py-24 md:px-8">
        <div class="mx-auto max-w-screen-2xl">
            <div class="mb-16 flex flex-col gap-4 md:flex-row md:items-baseline md:justify-between">
                <h2 class="binary-display text-4xl font-black uppercase tracking-tight md:text-6xl">Featured Projects</h2>
                <div class="binary-label text-sm uppercase tracking-[0.2em] text-[var(--binary-outline)]">&gt; view_all_repository</div>
            </div>

            <div class="flex flex-col gap-6">
                <article
                    v-for="(project, index) in projects"
                    :key="project.id"
                    class="flex w-full flex-col gap-12 rounded-[2rem] binary-card md:items-center"
                    :class="(index + 1) % 2 === 1 ? 'md:flex-row' : 'md:flex-row-reverse'"
                >
                    <div class="w-full md:w-1/2">
                        <span class="binary-label mb-4 block text-xs font-bold text-[var(--binary-primary)]">{{ project.id }} / {{ project.category }}</span>
                        <h3 class="binary-display mb-6 text-3xl font-bold uppercase md:text-5xl">{{ project.title }}</h3>
                        <ol v-if="Array.isArray(project.description)" class="mb-8 space-y-3 text-base leading-relaxed text-[var(--binary-text-muted)]">
                            <li v-for="(item, i) in project.description" :key="i" class="flex gap-3">
                                <span class="binary-label shrink-0 text-[var(--binary-primary)]">{{ i + 1 }}.</span>
                                <span>{{ item }}</span>
                            </li>
                        </ol>
                        <p v-else class="mb-8 text-lg leading-relaxed text-[var(--binary-text-muted)]">
                            {{ project.description }}
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <span
                                v-for="tag in project.tags"
                                :key="`${project.id}-${tag}`"
                                class="binary-chip binary-label text-[10px] uppercase"
                            >
                                &gt; {{ tag }}
                            </span>
                        </div>
                    </div>

                    <div v-if="project.image" class="w-full md:w-1/2">
                        <img
                            :src="project.image"
                            :alt="`${project.title} preview`"
                            class="h-full min-h-[280px] w-full rounded-[1.5rem] object-cover"
                        >
                    </div>

                    <!-- Commit log panel -->
                    <div
                        v-else-if="project.commits"
                        class="w-full rounded-[1.5rem] bg-[var(--binary-surface-lowest)] p-6 binary-label text-xs md:w-1/2"
                        style="box-shadow: inset 4px 0 0 0 var(--binary-primary);"
                    >
                        <div class="mb-4 flex items-center gap-2 text-[var(--binary-outline)]">
                            <span class="text-[var(--binary-primary)]">*</span>
                            <span>git log --oneline {{ project.id }}/main</span>
                        </div>
                        <div
                            v-for="commit in project.commits"
                            :key="commit.hash"
                            class="mb-3 flex items-start gap-3"
                        >
                            <span class="shrink-0 font-mono text-[var(--binary-primary)] opacity-70">{{ commit.hash }}</span>
                            <span class="shrink-0 text-[var(--binary-outline)]">{{ commit.date }}</span>
                            <span class="text-[var(--binary-text-muted)] leading-relaxed">{{ commit.message }}</span>
                            <span
                                v-if="commit.tag"
                                class="ml-auto shrink-0 rounded px-1.5 py-0.5 text-[9px] uppercase bg-[var(--binary-primary)]/10 text-[var(--binary-primary)]"
                            >{{ commit.tag }}</span>
                        </div>
                    </div>

                    <!-- Fallback terminal panel -->
                    <div
                        v-else
                        class="w-full rounded-[1.5rem] bg-[var(--binary-surface-lowest)] p-6 binary-label text-sm md:w-1/2"
                        style="box-shadow: inset 4px 0 0 0 var(--binary-primary);"
                    >
                        <div class="mb-2 flex gap-4">
                            <span class="text-[var(--binary-outline)]">01</span>
                            <span class="text-[var(--binary-primary)]">&gt; project: {{ project.id }}</span>
                        </div>
                        <div class="mb-2 flex gap-4">
                            <span class="text-[var(--binary-outline)]">02</span>
                            <span class="pl-4 text-[var(--binary-text)]">&gt; domain: {{ project.category }}</span>
                        </div>
                        <div
                            v-for="(tag, tagIndex) in project.tags"
                            :key="`${project.id}-panel-${tag}`"
                            class="mb-2 flex gap-4"
                        >
                            <span class="text-[var(--binary-outline)]">{{ String(tagIndex + 3).padStart(2, '0') }}</span>
                            <span class="pl-4 text-[var(--binary-text-muted)]">&gt; stack: {{ tag }}</span>
                        </div>
                        <div class="mt-2 flex gap-4">
                            <span class="text-[var(--binary-outline)]">{{ String(project.tags.length + 3).padStart(2, '0') }}</span>
                            <span class="text-[var(--binary-primary)]">status: online</span>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </section>
</template>

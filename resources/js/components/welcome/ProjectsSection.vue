<script setup lang="ts">
import { computed } from 'vue';
import { useCardEffects } from '../../composables/useCardEffects';
import { useCardEffectsBlob } from '../../composables/useCardEffectsBlob';
import { useTheme } from '../../composables/useTheme';

const { theme } = useTheme();
useCardEffects('.js-tilt-card', theme);
useCardEffectsBlob('.blob-card', theme);

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
    status?: string;
    image?: string;
    commits?: Commit[];
    link?: string;
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
    tags: [
        'Airports',
        'Airlines',
        'Countries',
        'Cities',
        'Wikidata',
        'D3 Globe',
        'Queue',
        'Rate Limit',
    ],
    image: '/images/projects/project03.webp',
};

const lineAutomationProject: Project = {
    id: '04',
    category: 'LINE_AUTOMATION',
    title: 'LINE Article Automation',
    description:
        '整合 LINE Bot 與 Laravel 內部 API，支援好友綁定、快速產文、完成通知 webhook，並加上 API key 與 HMAC 驗證，確保跨主機傳輸安全與流程可追蹤。',
    tags: ['LINE Messaging API', 'Webhook', 'HMAC', 'Queue'],
    status: 'paused',
    image: '/images/projects/project04.webp',
};

const miniOrchProject: Project = {
    id: '05',
    category: 'MINI_ORCHESTRATOR',
    title: 'Mini Orchestrator',
    description: [
        "雙節點實體網路環境，以 Ansible 自動化部署服務，涵蓋 Let's Encrypt 自簽憑證自動更新與 SNMP 監控。",
        '以 Go 實作 Worker 服務，搭配 Redis 作為任務佇列；API 全程走 HTTPS，透過 k6 持續壓測驗證穩定性。',
        '在 Laravel 後台整合 mini-orch 代理介面，一鍵觸發壓測並即時追蹤 Run 狀態。',
    ],
    tags: [
        'Ansible',
        'Go',
        'Python',
        'Redis',
        "Let's Encrypt",
        'SNMP',
        'k6',
        'HTTPS API',
        'Laravel Proxy',
    ],
    image: '/images/projects/project05.webp',
};

const tourPlaygroundProject: Project = {
    id: '06',
    category: 'TRAVEL_MANAGEMENT',
    title: 'Tour Playground',
    description: [
        '預載 100k 旅客、1k 行程假資料，支援依角色（付款人／同行人）隨機抽取，模擬真實購票場景。',
        '訂單採悲觀鎖防超賣，狀態機涵蓋 Reserved → Confirmed，逾時 15 分鐘自動釋放。',
        'Queue Job 非同步產出 CSV，前端每 2 秒 polling 追蹤匯出狀態，完成後即可下載。',
    ],
    tags: [
        'Laravel',
        'SQLite',
        'Pessimistic Lock',
        'Queue',
        'CSV Export',
        'Inertia',
    ],
    status: 'online',
    image: '/images/projects/project06.webp',
};

const storyRelayProject: Project = {
    id: '07',
    category: 'AI_STORY_RELAY',
    title: '故事接龍 & 角色創造',
    description: [
        '多個 LLM 角色輪流接龍，每位角色擁有獨立人設、記憶與行動傾向，共同推進同一個世界狀態。',
        '道具系統與事件觸發機制讓劇情產生分岔；每 2 小時自動排程推進，確保故事持續演化。',
        '同時僅允許一個 active session，玩家可建立角色加入世界，與 AI 共同創作。',
    ],
    tags: [
        'Multi-LLM',
        'Gemini',
        'Laravel Queue',
        'Character',
        'World State',
        'Cron',
    ],
    status: 'in_dev',
    image: '/images/projects/project07.webp',
};

const wsLabGachaProject: Project = {
    id: '08',
    category: 'WEBSOCKET_LAB',
    title: 'WebSocket Lab & 抽獎機台',
    description: [
        '以 Go 實作多房間 WebSocket server，每個房間獨立 goroutine event loop，透過 Redis 驗證身份。',
        'Host 機制讓房主控制機台狀態並即時廣播；Matter.js 物理引擎驅動彈珠抽卡動畫。',
        '每 IP 連線數限制與訊息速率控制，防止單一來源佔用房間資源。',
    ],
    tags: [
        'Go',
        'WebSocket',
        'Goroutine',
        'Redis',
        'Matter.js',
        'Vue 3',
        'Cloudflare',
    ],
    status: 'online',
    image: '/images/projects/project08.webp',
};

const computerVisionProject: Project = {
    id: '09',
    category: 'WASM_COMPUTER_VISION',
    title: 'WebAssembly + OpenCV',
    description: [
        '將 OpenCV 編譯為 WebAssembly，在瀏覽器內即時對攝影機影像執行邊緣偵測，無需後端運算。',
        '支援四種演算法切換：Canny（雙閾值）、Laplacian、Sobel、Scharr，並可疊加高斯模糊、反相與原圖疊加。',
        '純前端架構，WASM 模組載入後直接在 canvas 上逐幀處理，不佔用 UI 執行緒。',
    ],
    tags: [
        'WebAssembly',
        'OpenCV',
        'Canny',
        'Laplacian',
        'Sobel',
        'Camera API',
        'Vue 3',
    ],
    status: 'online',
    image: '/images/projects/project09.webp',
    link: '/app/computer-vision',
};

const mcpTodoProject: Project = {
    id: '10',
    category: 'MCP_TASK_MANAGEMENT',
    title: 'MCP Server & Task Management',
    description: [
        '以 Laravel 實作 MCP Server，提供 Claude Code 可直接呼叫的 tool API，達成 AI ↔ 後端雙向互動。',
        'Task Server 支援任務狀態（todo / in_progress / done）、子項目 checklist、跨專案 project 標籤，AI 可直接建立與追蹤任務。',
        'API Key Scope 系統讓不同工具取得不同授權，task:mcp 與 memory:mcp 各自獨立管控。',
    ],
    tags: [
        'MCP',
        'Laravel',
        'Claude Code',
        'Task Management',
        'API Key',
        'Vue 3',
    ],
    status: 'in_dev',
    image: '/images/projects/project10.webp',
    link: '/app/mcp',
};

const knowledgeGraphProject: Project = {
    id: '11',
    category: 'KNOWLEDGE_GRAPH',
    title: 'AI Knowledge Graph',
    description: [
        '實作知識圖譜 MCP Server（Entity / Relation / Observation），讓 AI 跨專案、跨機器共享結構化背景知識。',
        '以 D3 Force Graph 視覺化節點關係，節點大小代表知識密度；Topology 視圖以分層容器呈現部署拓樸。',
        '知識圖譜與 Task 系統分工：圖譜記錄「知道什麼」，Task 追蹤「要做什麼」，由 AI 在對話中判斷連結。',
    ],
    tags: [
        'MCP',
        'Knowledge Graph',
        'D3.js',
        'Laravel',
        'Claude Code',
        'Vue 3',
    ],
    status: 'in_dev',
    image: '/images/projects/project11.webp',
    link: '/app/memory',
};

const projects = computed(() => [
    ...props.featuredProjects,
    airportProject,
    lineAutomationProject,
    miniOrchProject,
    tourPlaygroundProject,
    storyRelayProject,
    wsLabGachaProject,
    computerVisionProject,
    mcpTodoProject,
    knowledgeGraphProject,
]);
</script>

<template>
    <section id="projects" class="binary-section bg-[var(--binary-surface)]">
        <div class="mx-auto max-w-screen-2xl">
            <div
                class="mb-8 flex flex-col gap-4 md:mb-16 md:flex-row md:items-baseline md:justify-between"
            >
                <h2
                    class="binary-display text-2xl font-black tracking-tight uppercase md:text-6xl"
                >
                    Featured Projects
                </h2>
                <div
                    class="binary-label text-sm tracking-[0.2em] text-[var(--binary-outline)] uppercase"
                >
                    &gt; view_all_repository
                </div>
            </div>

            <div class="flex flex-col gap-6">
                <div
                    v-for="(project, index) in projects"
                    :key="project.id"
                    class="relative"
                >
                    <article
                        class="binary-card relative flex w-full flex-col gap-6 transition-opacity md:items-center md:gap-12"
                        :class="[
                            theme === 'emerald' ? 'js-tilt-card' : 'blob-card',
                            (index + 1) % 2 === 1
                                ? 'md:flex-row'
                                : 'md:flex-row-reverse',
                            project.status === 'paused'
                                ? 'opacity-40 grayscale'
                                : '',
                        ]"
                    >
                        <div class="w-full md:w-1/2">
                            <span
                                class="binary-label mb-4 block text-xs font-bold text-[var(--binary-primary)]"
                                >{{ project.id }} / {{ project.category }}</span
                            >
                            <h3
                                class="binary-display mb-4 text-xl font-bold uppercase md:mb-6 md:text-5xl"
                            >
                                {{ project.title }}
                            </h3>
                            <ol
                                v-if="Array.isArray(project.description)"
                                class="mb-5 space-y-3 text-xs leading-relaxed text-[var(--binary-text-muted)] md:mb-8 md:text-base"
                            >
                                <li
                                    v-for="(item, i) in project.description"
                                    :key="i"
                                    class="flex gap-3"
                                >
                                    <span
                                        class="binary-label shrink-0 text-[var(--binary-primary)]"
                                        >{{ i + 1 }}.</span
                                    >
                                    <span>{{ item }}</span>
                                </li>
                            </ol>
                            <p
                                v-else
                                class="mb-5 text-xs leading-relaxed text-[var(--binary-text-muted)] md:mb-8 md:text-lg"
                            >
                                {{ project.description }}
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <span
                                    v-for="tag in project.tags"
                                    :key="`${project.id}-${tag}`"
                                    class="binary-chip binary-label text-[9px] uppercase"
                                >
                                    &gt; {{ tag }}
                                </span>
                            </div>
                        </div>

                        <div v-if="project.image" class="w-full md:w-1/2">
                            <img
                                :src="project.image"
                                :alt="`${project.title} preview`"
                                class="h-full min-h-[280px] w-full rounded-none object-cover md:rounded-2xl"
                            />
                        </div>

                        <!-- Commit log panel -->
                        <div
                            v-else-if="project.commits"
                            class="binary-label w-full rounded-none bg-[var(--binary-surface-lowest)] p-4 text-[10px] md:w-1/2 md:rounded-2xl md:p-6 md:text-xs"
                            style="
                                box-shadow: inset 4px 0 0 0
                                    var(--binary-primary);
                            "
                        >
                            <div
                                class="mb-4 flex items-center gap-2 text-[var(--binary-outline)]"
                            >
                                <span class="text-[var(--binary-primary)]"
                                    >*</span
                                >
                                <span
                                    >git log --oneline
                                    {{ project.id }}/main</span
                                >
                            </div>
                            <div
                                v-for="commit in project.commits"
                                :key="commit.hash"
                                class="mb-3 md:flex md:items-start md:gap-3"
                            >
                                <div
                                    class="flex items-center gap-3 md:contents"
                                >
                                    <span
                                        class="shrink-0 font-mono text-[var(--binary-primary)] opacity-70"
                                        >{{ commit.hash }}</span
                                    >
                                    <span
                                        class="shrink-0 text-[var(--binary-outline)]"
                                        >{{ commit.date }}</span
                                    >
                                </div>
                                <span
                                    class="block leading-relaxed break-words text-[var(--binary-text-muted)] md:min-w-0"
                                    >{{ commit.message }}</span
                                >
                                <span
                                    v-if="commit.tag"
                                    class="mt-1 inline-block shrink-0 rounded bg-[var(--binary-primary)]/10 px-1.5 py-0.5 text-[9px] text-[var(--binary-primary)] uppercase md:mt-0 md:ml-auto"
                                    >{{ commit.tag }}</span
                                >
                            </div>
                        </div>

                        <!-- Fallback terminal panel -->
                        <div
                            v-else
                            class="binary-label w-full rounded-none bg-[var(--binary-surface-lowest)] p-4 text-sm md:w-1/2 md:rounded-2xl md:p-6"
                            style="
                                box-shadow: inset 4px 0 0 0
                                    var(--binary-primary);
                            "
                        >
                            <div class="mb-2 flex gap-4">
                                <span class="text-[var(--binary-outline)]"
                                    >01</span
                                >
                                <span class="text-[var(--binary-primary)]"
                                    >&gt; project: {{ project.id }}</span
                                >
                            </div>
                            <div class="mb-2 flex gap-4">
                                <span class="text-[var(--binary-outline)]"
                                    >02</span
                                >
                                <span class="pl-4 text-[var(--binary-text)]"
                                    >&gt; domain: {{ project.category }}</span
                                >
                            </div>
                            <div
                                v-for="(tag, tagIndex) in project.tags"
                                :key="`${project.id}-panel-${tag}`"
                                class="mb-2 flex gap-4"
                            >
                                <span class="text-[var(--binary-outline)]">{{
                                    String(tagIndex + 3).padStart(2, '0')
                                }}</span>
                                <span
                                    class="pl-4 text-[var(--binary-text-muted)]"
                                    >&gt; stack: {{ tag }}</span
                                >
                            </div>
                            <div class="mt-2 flex gap-4">
                                <span class="text-[var(--binary-outline)]">{{
                                    String(project.tags.length + 3).padStart(
                                        2,
                                        '0',
                                    )
                                }}</span>
                                <span class="text-[var(--binary-primary)]"
                                    >status:
                                    {{ project.status ?? 'online' }}</span
                                >
                            </div>
                            <a
                                v-if="project.link"
                                :href="project.link"
                                class="mt-5 inline-flex items-center gap-2 rounded-lg border border-[var(--binary-primary)]/40 px-4 py-2 text-[11px] text-[var(--binary-primary)] uppercase transition hover:bg-[var(--binary-primary)]/10"
                                >&gt; visit_project</a
                            >
                        </div>
                        <div
                            class="glow rounded-inherit pointer-events-none absolute inset-0 z-10"
                        ></div>
                        <canvas
                            class="border-canvas pointer-events-none absolute inset-0 z-10"
                        ></canvas>
                    </article>
                </div>
            </div>
        </div>
    </section>
</template>

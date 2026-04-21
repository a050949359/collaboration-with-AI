<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { logoutWithApi } from '../lib/auth-api';

const page = usePage();
const currentUser = computed(() => page.props.auth?.user);
const isLoggingOut = ref(false);

async function logout() {
    if (isLoggingOut.value) return;
    isLoggingOut.value = true;
    try {
        await logoutWithApi();
        router.visit('/', { replace: true });
    } finally {
        isLoggingOut.value = false;
    }
}

const featuredProjects = [
    {
        id: '01',
        category: 'ARCHITECTURE',
        title: 'Distributed Event Mesh',
        description:
            '基於 NATS 與 Go 開發的異步事件驅動架構，支援每秒 500k+ 訊息吞吐量，實現跨雲端環境的極低延遲數據同步。',
        tags: ['Go', 'NATS', 'gRPC'],
    },
    {
        id: '02',
        category: 'DATABASE',
        title: 'Sharding Proxy',
        description: '自動化的資料庫分庫分表代理層，解決水平擴展瓶頸。',
        tags: ['Rust', 'PostgreSQL'],
    },
];

const articles = [
    {
        date: '2024.11.14',
        category: 'TECH_LOG',
        title: '深入解析 Go 語言的高併發調度機制與 GC 優化實踐',
        description:
            '探討 GPM 調度模型在處理大規模長連接時的表現，以及如何透過 pprof 進行內存洩漏定位與優化。',
        tags: ['RUNTIME', 'OPTIMIZATION', 'GO'],
    },
    {
        date: '2024.10.28',
        category: 'ARCHITECTURE',
        title: '為什麼在 2024 年你應該考慮將核心邏輯遷移至 Rust',
        description:
            '從內存安全與執行效率的角度出發，分析 Rust 在後端關鍵服務中的落地應用價值。',
        tags: ['RUST', 'SYSTEMS_DESIGN', 'BACKEND'],
    },
    {
        date: '2024.10.05',
        category: 'INFRA',
        title: 'Kubernetes 控制器模式：從原理到自定義 Operator 開發',
        description:
            '理解 Reconcile Loop 的核心邏輯，並分享如何透過 Kubebuilder 開發高效的資源管理工具。',
        tags: ['K8S', 'OPERATOR', 'CLOUD_NATIVE'],
    },
];

const stackInfo = [
    ['Language', 'Go, Rust, TypeScript'],
    ['Database', 'PostgreSQL, Redis'],
    ['Infra', 'K8s, Docker, AWS'],
];
</script>

<template>
    <Head title="Home" />

    <div class="binary-page selection:bg-[var(--binary-primary-container)] selection:text-[var(--binary-on-primary-container)]">
        <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
            <div class="absolute inset-0 binary-grid opacity-[0.03]" />
            <div class="absolute right-0 top-0 h-[40vw] w-[40vw] bg-[#6bdc9f]/[0.06] blur-[140px]" />
            <div class="absolute bottom-0 left-0 h-[30vw] w-[30vw] bg-[#2ca46d]/16 blur-[120px]" />
        </div>

        <nav class="binary-glass fixed left-0 right-0 top-0 z-50">
            <div class="mx-auto flex w-full max-w-screen-2xl items-center justify-between px-6 py-4 md:px-8">
                <div class="binary-display text-xl font-black uppercase tracking-tight text-[var(--binary-primary)]">
                    BINARY_EDITORIAL
                </div>

                <div class="hidden items-center gap-8 binary-label text-xs uppercase text-[var(--binary-outline)] md:flex">
                    <a class="binary-link hover:text-[var(--binary-primary)]" href="#projects">Projects</a>
                    <a class="binary-link hover:text-[var(--binary-primary)]" href="#articles">Articles</a>
                    <a class="binary-link text-[var(--binary-primary)]" href="#about">About</a>
                </div>

                <div class="flex items-center gap-3">
                    <template v-if="currentUser">
                        <div class="binary-chip hidden sm:inline-flex">
                            {{ currentUser.name }}
                        </div>
                        <button
                            type="button"
                            :disabled="isLoggingOut"
                            class="rounded-md px-6 py-2 binary-display text-xs font-bold uppercase text-[var(--binary-on-primary-container)] cursor-pointer disabled:opacity-50"
                            style="background: linear-gradient(145deg, var(--binary-primary) 0%, var(--binary-primary-container) 100%);"
                            @click="logout"
                        >
                            {{ isLoggingOut ? 'Logging out...' : 'Logout' }}
                        </button>
                    </template>
                    <template v-else>
                        <Link class="binary-ghost-button hidden sm:inline-flex" href="/login">Login</Link>
                        <Link class="rounded-md px-6 py-2 binary-display text-xs font-bold uppercase text-[var(--binary-on-primary-container)]" href="/register" style="background: linear-gradient(145deg, var(--binary-primary) 0%, var(--binary-primary-container) 100%);">
                            Connect
                        </Link>
                    </template>
                </div>
            </div>
        </nav>

        <main class="pt-24">
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

            <section id="projects" class="bg-[var(--binary-surface)] px-6 py-24 md:px-8">
                <div class="mx-auto max-w-screen-2xl">
                    <div class="mb-16 flex flex-col gap-4 md:flex-row md:items-baseline md:justify-between">
                        <h2 class="binary-display text-4xl font-black uppercase tracking-tight md:text-6xl">Featured_Projects</h2>
                        <div class="binary-label text-sm uppercase tracking-[0.2em] text-[var(--binary-outline)]">&gt; view_all_repository</div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-12">
                        <article class="group relative overflow-hidden rounded-[2rem] binary-card transition-colors hover:bg-[var(--binary-surface-high)] md:col-span-8">
                            <div class="relative z-10">
                                <span class="binary-label mb-4 block text-xs font-bold text-[var(--binary-primary)]">
                                    {{ featuredProjects[0].id }} / {{ featuredProjects[0].category }}
                                </span>
                                <h3 class="binary-display mb-4 text-3xl font-bold uppercase">{{ featuredProjects[0].title }}</h3>
                                <p class="mb-8 max-w-lg leading-relaxed text-[var(--binary-text-muted)]">
                                    {{ featuredProjects[0].description }}
                                </p>
                                <div class="flex flex-wrap gap-2">
                                    <span
                                        v-for="tag in featuredProjects[0].tags"
                                        :key="tag"
                                        class="binary-chip binary-label text-[10px] uppercase"
                                    >
                                        &gt; {{ tag }}
                                    </span>
                                </div>
                            </div>
                            <div class="absolute right-0 top-0 p-8 text-[120px] opacity-10 transition-opacity group-hover:opacity-30">
                                <span class="binary-display">#</span>
                            </div>
                        </article>

                        <article class="rounded-[2rem] binary-card-raised transition-colors hover:bg-[var(--binary-surface-highest)] md:col-span-4">
                            <span class="binary-label mb-4 block text-xs font-bold text-[var(--binary-primary)]">
                                {{ featuredProjects[1].id }} / {{ featuredProjects[1].category }}
                            </span>
                            <h3 class="binary-display mb-4 text-3xl font-bold uppercase">{{ featuredProjects[1].title }}</h3>
                            <p class="mb-12 leading-relaxed text-[var(--binary-text-muted)]">
                                {{ featuredProjects[1].description }}
                            </p>
                            <div class="mb-6 h-48 rounded-[1.5rem] bg-[linear-gradient(145deg,#111813_0%,#18241d_48%,#223129_100%)]" />
                            <div class="flex flex-wrap gap-2">
                                <span
                                    v-for="tag in featuredProjects[1].tags"
                                    :key="tag"
                                    class="binary-chip binary-label text-[10px] uppercase"
                                >
                                    &gt; {{ tag }}
                                </span>
                            </div>
                        </article>

                        <article class="flex flex-col gap-12 rounded-[2rem] binary-card md:col-span-12 md:flex-row md:items-center">
                            <div class="w-full md:w-1/2">
                                <span class="binary-label mb-4 block text-xs font-bold text-[var(--binary-primary)]">03 / SECURITY</span>
                                <h3 class="binary-display mb-6 text-3xl font-bold uppercase md:text-5xl">Auth-Shield Zero Trust</h3>
                                <p class="mb-8 text-lg leading-relaxed text-[var(--binary-text-muted)]">
                                    實現基於身份的存取控制系統，結合 OIDC 與自研授權引擎，確保微服務架構中的每一層請求都經過驗證與授權。
                                </p>
                                <button class="binary-ghost-button px-8 py-3 text-[10px]" type="button">[ execute_analysis ]</button>
                            </div>

                            <div class="w-full rounded-[1.5rem] bg-[var(--binary-surface-lowest)] p-6 binary-label text-sm md:w-1/2" style="box-shadow: inset 4px 0 0 0 var(--binary-primary);">
                                <div class="mb-2 flex gap-4">
                                    <span class="text-[var(--binary-outline)]">01</span>
                                    <span class="text-[var(--binary-primary)]">func ValidateIdentity(ctx context.Context) {</span>
                                </div>
                                <div class="mb-2 flex gap-4">
                                    <span class="text-[var(--binary-outline)]">02</span>
                                    <span class="pl-4 text-[var(--binary-text)]">token := ctx.Value("auth_token")</span>
                                </div>
                                <div class="mb-2 flex gap-4">
                                    <span class="text-[var(--binary-outline)]">03</span>
                                    <span class="pl-4 text-[var(--binary-text-muted)]">if token == nil { return ErrUnauthorized }</span>
                                </div>
                                <div class="mb-2 flex gap-4">
                                    <span class="text-[var(--binary-outline)]">04</span>
                                    <span class="pl-4 text-[var(--binary-primary)]">// Initializing Zero-Trust Check</span>
                                </div>
                                <div class="flex gap-4">
                                    <span class="text-[var(--binary-outline)]">05</span>
                                    <span class="pl-4 text-[var(--binary-primary)]">return Engine.Verify(token)</span>
                                </div>
                                <div class="mt-2 flex gap-4">
                                    <span class="text-[var(--binary-outline)]">06</span>
                                    <span class="text-[var(--binary-primary)]">}</span>
                                </div>
                            </div>
                        </article>
                    </div>
                </div>
            </section>

            <section id="articles" class="mx-auto grid max-w-screen-2xl grid-cols-1 gap-12 px-6 py-24 md:grid-cols-12 md:px-8">
                <div class="md:col-span-4">
                    <h2 class="binary-display sticky top-32 text-4xl font-black uppercase tracking-tight md:text-6xl">
                        Latest
                        <br>
                        Articles
                    </h2>
                </div>

                <div class="space-y-12 md:col-span-8">
                    <article v-for="article in articles" :key="article.title" class="group cursor-pointer">
                        <div v-if="article.title !== articles[0].title" class="mb-12 h-px bg-white/8" />
                        <div class="mb-4 flex items-start justify-between">
                            <span class="binary-label text-xs uppercase text-[var(--binary-outline)]">
                                {{ article.date }} / {{ article.category }}
                            </span>
                            <span class="text-[var(--binary-outline)] transition-colors group-hover:text-[var(--binary-primary)]">↗</span>
                        </div>
                        <h3 class="binary-display mb-4 text-3xl font-bold uppercase leading-tight transition-colors group-hover:text-[var(--binary-primary)]">
                            {{ article.title }}
                        </h3>
                        <p class="mb-6 leading-relaxed text-[var(--binary-text-muted)]">
                            {{ article.description }}
                        </p>
                        <div class="flex flex-wrap gap-4 binary-label text-[10px] uppercase text-[var(--binary-outline)]">
                            <span v-for="tag in article.tags" :key="tag">#{{ tag }}</span>
                        </div>
                    </article>
                </div>
            </section>

            <section class="bg-[linear-gradient(145deg,#6bdc9f_0%,#2ca46d_100%)] px-6 py-24 md:px-8">
                <div class="mx-auto flex max-w-screen-2xl flex-col items-center text-center text-[var(--binary-on-primary-container)]">
                    <span class="binary-label mb-8 text-xs uppercase tracking-[0.4em]">Ready to sync?</span>
                    <h2 class="binary-display mb-12 text-5xl font-black uppercase leading-none tracking-tight md:text-8xl">
                        START_A_
                        <br>
                        NEW_MODULE
                    </h2>

                    <div class="w-full max-w-xl">
                        <div class="group relative">
                            <input
                                class="w-full border-0 border-b-2 border-[var(--binary-on-primary-container)] bg-transparent py-4 binary-label text-lg placeholder:text-[color:rgba(0,113,23,0.45)] focus:outline-none"
                                placeholder="ENTER_EMAIL_ADDRESS"
                                type="email"
                            >
                        </div>

                        <div class="mt-8 flex flex-col justify-center gap-4 sm:flex-row">
                            <template v-if="currentUser">
                                <div class="rounded-md bg-[#0f1511] px-8 py-4 binary-display text-sm font-bold uppercase text-[var(--binary-primary)]">
                                    Welcome Back
                                </div>
                                <div class="rounded-md px-8 py-4 binary-display text-sm font-bold uppercase text-[var(--binary-on-primary-container)]" style="background-color: rgba(15, 21, 17, 0.08);">
                                    {{ currentUser.email }}
                                </div>
                            </template>
                            <template v-else>
                                <Link class="rounded-md bg-[#0f1511] px-8 py-4 binary-display text-sm font-bold uppercase text-[var(--binary-primary)]" href="/login">
                                    Login Console
                                </Link>
                                <Link class="rounded-md px-8 py-4 binary-display text-sm font-bold uppercase text-[var(--binary-on-primary-container)]" href="/register" style="background-color: rgba(15, 21, 17, 0.08);">
                                    Create Node
                                </Link>
                            </template>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>

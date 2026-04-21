<script setup lang="ts">
interface Project {
    id: string;
    category: string;
    title: string;
    description: string;
    tags: string[];
}

defineProps<{
    featuredProjects: Project[];
}>();
</script>

<template>
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
                        <span class="binary-label mb-4 block text-xs font-bold text-[var(--binary-primary)]">03 / AIRPORT_PLATFORM</span>
                        <h3 class="binary-display mb-6 text-3xl font-bold uppercase md:text-5xl">Global Airport Intelligence</h3>
                        <p class="mb-8 text-lg leading-relaxed text-[var(--binary-text-muted)]">
                            提供機場資料搜尋、洲別/類型統計與互動地球視圖。可點擊國家即時查詢該國機場，並把經緯度標示為大頭針，同時對查詢 API 加上每分鐘限流控制。
                        </p>
                        <button class="binary-ghost-button px-8 py-3 text-[10px]" type="button">[ open_airport_console ]</button>
                    </div>

                    <div class="w-full rounded-[1.5rem] bg-[var(--binary-surface-lowest)] p-6 binary-label text-sm md:w-1/2" style="box-shadow: inset 4px 0 0 0 var(--binary-primary);">
                        <div class="mb-2 flex gap-4">
                            <span class="text-[var(--binary-outline)]">01</span>
                            <span class="text-[var(--binary-primary)]">GET /api/v1/airports?country=JP&amp;per_page=1000</span>
                        </div>
                        <div class="mb-2 flex gap-4">
                            <span class="text-[var(--binary-outline)]">02</span>
                            <span class="pl-4 text-[var(--binary-text)]">→ normalize(country) &amp; apply filters</span>
                        </div>
                        <div class="mb-2 flex gap-4">
                            <span class="text-[var(--binary-outline)]">03</span>
                            <span class="pl-4 text-[var(--binary-text-muted)]">→ paginated response (meta + links)</span>
                        </div>
                        <div class="mb-2 flex gap-4">
                            <span class="text-[var(--binary-outline)]">04</span>
                            <span class="pl-4 text-[var(--binary-primary)]">→ render pins by lat/lon on 3D globe</span>
                        </div>
                        <div class="flex gap-4">
                            <span class="text-[var(--binary-outline)]">05</span>
                            <span class="pl-4 text-[var(--binary-primary)]">throttle: 5 requests / minute</span>
                        </div>
                        <div class="mt-2 flex gap-4">
                            <span class="text-[var(--binary-outline)]">06</span>
                            <span class="text-[var(--binary-primary)]">status: online</span>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </section>
</template>

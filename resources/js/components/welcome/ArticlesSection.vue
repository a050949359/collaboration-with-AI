<script setup lang="ts">
interface Article {
    date: string;
    category: string;
    title: string;
    description: string;
    tags: string[];
}

defineProps<{
    articles: Article[];
}>();
</script>

<template>
    <section
        id="articles"
        class="binary-section mx-auto grid min-h-[calc(100vh-4rem)] max-w-screen-2xl grid-cols-1 content-center gap-8 md:grid-cols-12 md:gap-12"
    >
        <div class="md:col-span-4">
            <h2
                class="binary-display text-2xl font-black tracking-tight uppercase md:sticky md:top-32 md:text-6xl"
            >
                Latest
                <br />
                Articles
            </h2>
        </div>

        <div class="space-y-8 md:col-span-8 md:space-y-12">
            <article
                v-for="article in articles"
                :key="article.title"
                class="group cursor-pointer"
            >
                <div
                    v-if="article.title !== articles[0].title"
                    class="mb-8 h-px bg-white/8 md:mb-12"
                />
                <div class="mb-4 flex items-start justify-between">
                    <span
                        class="binary-label text-xs text-[var(--binary-outline)] uppercase"
                    >
                        {{ article.date }} / {{ article.category }}
                    </span>
                    <span
                        class="text-[var(--binary-outline)] transition-colors group-hover:text-[var(--binary-primary)]"
                        >↗</span
                    >
                </div>
                <h3
                    class="binary-display mb-4 text-xl leading-tight font-bold uppercase transition-colors group-hover:text-[var(--binary-primary)] md:text-3xl"
                >
                    {{ article.title }}
                </h3>
                <p class="mb-6 leading-relaxed text-[var(--binary-text-muted)]">
                    {{ article.description }}
                </p>
                <div
                    class="binary-label flex flex-wrap gap-4 text-[9px] text-[var(--binary-outline)] uppercase"
                >
                    <span v-for="tag in article.tags" :key="tag"
                        >#{{ tag }}</span
                    >
                </div>
            </article>
        </div>
    </section>
</template>

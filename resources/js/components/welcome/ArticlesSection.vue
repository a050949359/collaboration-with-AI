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
        class="mx-auto grid max-w-screen-2xl grid-cols-1 gap-12 px-6 py-24 md:grid-cols-12 md:px-8"
    >
        <div class="md:col-span-4">
            <h2
                class="binary-display sticky top-32 text-4xl font-black tracking-tight uppercase md:text-6xl"
            >
                Latest
                <br />
                Articles
            </h2>
        </div>

        <div class="space-y-12 md:col-span-8">
            <article
                v-for="article in articles"
                :key="article.title"
                class="group cursor-pointer"
            >
                <div
                    v-if="article.title !== articles[0].title"
                    class="mb-12 h-px bg-white/8"
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
                    class="binary-display mb-4 text-3xl leading-tight font-bold uppercase transition-colors group-hover:text-[var(--binary-primary)]"
                >
                    {{ article.title }}
                </h3>
                <p class="mb-6 leading-relaxed text-[var(--binary-text-muted)]">
                    {{ article.description }}
                </p>
                <div
                    class="binary-label flex flex-wrap gap-4 text-[10px] text-[var(--binary-outline)] uppercase"
                >
                    <span v-for="tag in article.tags" :key="tag"
                        >#{{ tag }}</span
                    >
                </div>
            </article>
        </div>
    </section>
</template>

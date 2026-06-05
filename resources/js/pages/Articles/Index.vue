<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import { useAuth } from '@/composables/useAuth';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    ArticleApiError,
    fetchAuthArticles,
    fetchPublicArticles,
} from '@/lib/articles-api';
import type { ArticlePreview } from '@/lib/articles-api';
import { routes } from '@/lib/routes';

const { t } = useI18n();
const { isLoggedIn: isAuthenticated } = useAuth();
const isLoading = ref(false);
const errorMessage = ref('');

async function handleCreateArticle() {
    window.location.href = routes.articles.generate();
}

const guestArticles = ref<ArticlePreview[]>([]);
const authArticles = ref<ArticlePreview[]>([]);
const scope = ref<'all' | 'mine'>('all');

const currentPage = ref(1);
const lastPage = ref(1);
const total = ref(0);
const perPage = ref(10);

const activeItems = computed(() =>
    isAuthenticated.value ? authArticles.value : guestArticles.value,
);

function formatDate(isoDate: string) {
    const date = new Date(isoDate);

    if (Number.isNaN(date.getTime())) {
        return isoDate;
    }

    return date.toISOString().slice(0, 10).replace(/-/g, '.');
}

function titleOf(article: ArticlePreview) {
    return (article.title || t('articles.index.untitled')).toUpperCase();
}

function summaryOf(article: ArticlePreview) {
    return article.summary || t('articles.index.no_summary');
}

async function loadGuestArticles() {
    guestArticles.value = await fetchPublicArticles(100);
}

async function loadAuthArticles(page = 1) {
    const response = await fetchAuthArticles(scope.value, page, perPage.value);

    authArticles.value = response.items;
    currentPage.value = response.currentPage;
    lastPage.value = response.lastPage;
    total.value = response.total;
}

async function reload(page = 1) {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        if (isAuthenticated.value) {
            await loadAuthArticles(page);
        } else {
            await loadGuestArticles();
        }
    } catch (error: unknown) {
        errorMessage.value =
            error instanceof ArticleApiError
                ? error.message
                : t('articles.index.load_failed');
    } finally {
        isLoading.value = false;
    }
}

async function changeScope(nextScope: 'all' | 'mine') {
    if (scope.value === nextScope) {
        return;
    }

    scope.value = nextScope;
    await reload(1);
}

onMounted(async () => {
    await reload(1);
});
</script>

<template>
    <Head :title="t('articles.index.head_title')" />

    <AppLayout>
        <main class="pb-24">
            <section
                class="binary-section mx-auto grid max-w-screen-2xl grid-cols-1 gap-8 md:grid-cols-12 md:gap-12"
            >
                <div class="md:col-span-4">
                    <h1
                        class="binary-display text-2xl font-black tracking-tight uppercase md:sticky md:top-32 md:text-6xl"
                    >
                        {{ t('articles.index.title').toUpperCase() }}
                    </h1>
                    <p class="mt-4 text-sm text-[var(--binary-text-muted)]">
                        {{
                            isAuthenticated
                                ? t('articles.index.mode_auth')
                                : t('articles.index.mode_public')
                        }}
                    </p>

                    <div
                        v-if="isAuthenticated"
                        class="mt-6 flex items-center justify-between gap-2"
                    >
                        <div class="flex gap-2">
                            <button
                                class="binary-label rounded-lg px-4 py-2 text-xs font-bold uppercase transition"
                                :class="
                                    scope === 'all'
                                        ? 'bg-[var(--binary-primary)] text-[var(--binary-on-primary-container)]'
                                        : 'bg-[var(--binary-surface-container)] text-[var(--binary-outline)] hover:text-[var(--binary-text)]'
                                "
                                @click="changeScope('all')"
                            >
                                {{ t('articles.index.scope_all') }}
                            </button>
                            <button
                                class="binary-label rounded-lg px-4 py-2 text-xs font-bold uppercase transition"
                                :class="
                                    scope === 'mine'
                                        ? 'bg-[var(--binary-primary)] text-[var(--binary-on-primary-container)]'
                                        : 'bg-[var(--binary-surface-container)] text-[var(--binary-outline)] hover:text-[var(--binary-text)]'
                                "
                                @click="changeScope('mine')"
                            >
                                {{ t('articles.index.scope_mine') }}
                            </button>
                        </div>

                        <button
                            class="binary-label flex items-center gap-1 rounded-lg bg-[var(--binary-primary)] px-4 py-2 text-xs font-bold text-[var(--binary-on-primary-container)] uppercase transition hover:opacity-80 disabled:opacity-40"
                            @click="handleCreateArticle"
                        >
                            <span>{{ t('articles.index.new_article') }}</span>
                        </button>
                    </div>
                </div>

                <div class="space-y-12 md:col-span-8">
                    <p
                        v-if="errorMessage"
                        class="rounded-lg border border-red-400/20 bg-red-950/20 px-4 py-3 text-sm text-red-300"
                    >
                        {{ errorMessage }}
                    </p>

                    <div
                        v-if="isLoading"
                        class="py-20 text-center text-sm text-[var(--binary-text-muted)]"
                    >
                        {{ t('articles.index.loading') }}
                    </div>

                    <div
                        v-else-if="!activeItems.length"
                        class="py-20 text-center text-sm text-[var(--binary-text-muted)]"
                    >
                        {{ t('articles.index.empty') }}
                    </div>

                    <article
                        v-for="(article, index) in activeItems"
                        :key="article.id"
                        class="group"
                    >
                        <div v-if="index > 0" class="mb-12 h-px bg-white/8" />
                        <a
                            :href="routes.articles.show(article.id)"
                            class="block"
                        >
                            <div class="mb-4 flex items-start justify-between">
                                <span
                                    class="binary-label text-xs text-[var(--binary-outline)] uppercase"
                                >
                                    {{ formatDate(article.created_at) }} /
                                    {{
                                        article.category ||
                                        t('articles.index.uncategorized')
                                    }}
                                </span>
                                <span
                                    class="text-[var(--binary-outline)] transition-colors group-hover:text-[var(--binary-primary)]"
                                    >↗</span
                                >
                            </div>
                            <h2
                                class="binary-display mb-4 text-xl leading-tight font-bold uppercase transition-colors group-hover:text-[var(--binary-primary)] md:text-3xl"
                            >
                                {{ titleOf(article) }}
                            </h2>
                            <p
                                class="mb-6 text-sm leading-relaxed text-[var(--binary-text-muted)] md:text-base"
                            >
                                {{ summaryOf(article) }}
                            </p>
                            <div
                                class="binary-label flex flex-wrap gap-4 text-[9px] text-[var(--binary-outline)] uppercase"
                            >
                                <span v-for="tag in article.tags" :key="tag"
                                    >#{{ tag }}</span
                                >
                            </div>
                        </a>
                    </article>

                    <div
                        v-if="
                            isAuthenticated && !isLoading && activeItems.length
                        "
                        class="mt-8 flex items-center justify-between gap-3"
                    >
                        <span class="text-xs text-[var(--binary-outline)]">
                            {{
                                t('articles.index.pagination', {
                                    total,
                                    current: currentPage,
                                    last: lastPage,
                                })
                            }}
                        </span>
                        <div class="flex shrink-0">
                            <button
                                class="binary-ghost-button rounded-none px-3 py-1.5 text-xs whitespace-nowrap disabled:opacity-30"
                                :disabled="currentPage <= 1"
                                @click="reload(currentPage - 1)"
                            >
                                {{ t('common.prev_page') }}
                            </button>
                            <button
                                class="binary-ghost-button rounded-none border-l border-[var(--binary-outline-variant)] px-3 py-1.5 text-xs whitespace-nowrap disabled:opacity-30"
                                :disabled="currentPage >= lastPage"
                                @click="reload(currentPage + 1)"
                            >
                                {{ t('common.next_page') }}
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </AppLayout>
</template>

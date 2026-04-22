<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import AppLayout from '@/layouts/AppLayout.vue';
import { ArticleApiError,  fetchAuthArticles, fetchPublicArticles } from '@/lib/articles-api';
import type {ArticlePreview} from '@/lib/articles-api';

const page = usePage();
const { t } = useI18n();
const isAuthenticated = computed(() => !!page.props.auth?.user);
const isLoading = ref(false);
const errorMessage = ref('');

async function handleCreateArticle() {
    window.location.href = '/articles/generate';
}

const guestArticles = ref<ArticlePreview[]>([]);
const authArticles = ref<ArticlePreview[]>([]);
const scope = ref<'all' | 'mine'>('all');

const currentPage = ref(1);
const lastPage = ref(1);
const total = ref(0);
const perPage = ref(10);

const activeItems = computed(() => (isAuthenticated.value ? authArticles.value : guestArticles.value));

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
        errorMessage.value = error instanceof ArticleApiError
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
        <main class="pt-24 pb-24">
            <section class="mx-auto grid max-w-screen-2xl grid-cols-1 gap-12 px-6 py-12 md:grid-cols-12 md:px-8">
                <div class="md:col-span-4">
                    <h1 class="binary-display sticky top-32 text-4xl font-black uppercase tracking-tight md:text-6xl">
                        {{ t('articles.index.title').toUpperCase() }}
                    </h1>
                    <p class="mt-4 text-sm text-[var(--binary-text-muted)]">
                        {{ isAuthenticated ? t('articles.index.mode_auth') : t('articles.index.mode_public') }}
                    </p>

                    <div v-if="isAuthenticated" class="mt-6 flex items-center justify-between gap-2">
                        <div class="flex gap-2">
                            <button
                                class="binary-label rounded-lg px-4 py-2 text-xs font-bold uppercase transition"
                                :class="scope === 'all'
                                    ? 'bg-[var(--binary-primary)] text-[var(--binary-on-primary-container)]'
                                    : 'bg-[var(--binary-surface-container)] text-[var(--binary-outline)] hover:text-[var(--binary-text)]'"
                                @click="changeScope('all')"
                            >
                                {{ t('articles.index.scope_all') }}
                            </button>
                            <button
                                class="binary-label rounded-lg px-4 py-2 text-xs font-bold uppercase transition"
                                :class="scope === 'mine'
                                    ? 'bg-[var(--binary-primary)] text-[var(--binary-on-primary-container)]'
                                    : 'bg-[var(--binary-surface-container)] text-[var(--binary-outline)] hover:text-[var(--binary-text)]'"
                                @click="changeScope('mine')"
                            >
                                {{ t('articles.index.scope_mine') }}
                            </button>
                        </div>

                        <button
                            class="binary-label flex items-center gap-1 rounded-lg bg-[var(--binary-primary)] px-4 py-2 text-xs font-bold uppercase text-[var(--binary-on-primary-container)] transition hover:opacity-80 disabled:opacity-40"
                            @click="handleCreateArticle"
                        >
                            <span>{{ t('articles.index.new_article') }}</span>
                        </button>
                    </div>
                </div>

                <div class="space-y-12 md:col-span-8">
                    <p v-if="errorMessage" class="rounded-lg border border-red-400/20 bg-red-950/20 px-4 py-3 text-sm text-red-300">
                        {{ errorMessage }}
                    </p>

                    <div v-if="isLoading" class="py-20 text-center text-sm text-[var(--binary-text-muted)]">
                        {{ t('articles.index.loading') }}
                    </div>

                    <div v-else-if="!activeItems.length" class="py-20 text-center text-sm text-[var(--binary-text-muted)]">
                        {{ t('articles.index.empty') }}
                    </div>

                    <article v-for="(article, index) in activeItems" :key="article.id" class="group">
                        <div v-if="index > 0" class="mb-12 h-px bg-white/8" />
                        <a :href="`/articles/${article.id}`" class="block">
                            <div class="mb-4 flex items-start justify-between">
                                <span class="binary-label text-xs uppercase text-[var(--binary-outline)]">
                                    {{ formatDate(article.created_at) }} / {{ article.category || t('articles.index.uncategorized') }}
                                </span>
                                <span class="text-[var(--binary-outline)] transition-colors group-hover:text-[var(--binary-primary)]">↗</span>
                            </div>
                            <h2 class="binary-display mb-4 text-3xl font-bold uppercase leading-tight transition-colors group-hover:text-[var(--binary-primary)]">
                                {{ titleOf(article) }}
                            </h2>
                            <p class="mb-6 leading-relaxed text-[var(--binary-text-muted)]">
                                {{ summaryOf(article) }}
                            </p>
                            <div class="flex flex-wrap gap-4 binary-label text-[10px] uppercase text-[var(--binary-outline)]">
                                <span v-for="tag in article.tags" :key="tag">#{{ tag }}</span>
                            </div>
                        </a>
                    </article>

                    <div v-if="isAuthenticated && !isLoading && activeItems.length" class="mt-8 flex items-center justify-between gap-4">
                        <span class="text-xs text-[var(--binary-outline)]">
                            {{ t('articles.index.pagination', { total, current: currentPage, last: lastPage }) }}
                        </span>
                        <div class="flex gap-2">
                            <button
                                class="binary-ghost-button px-4 py-1.5 text-xs disabled:opacity-30"
                                :disabled="currentPage <= 1"
                                @click="reload(currentPage - 1)"
                            >
                                {{ t('common.prev_page') }}
                            </button>
                            <button
                                class="binary-ghost-button px-4 py-1.5 text-xs disabled:opacity-30"
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

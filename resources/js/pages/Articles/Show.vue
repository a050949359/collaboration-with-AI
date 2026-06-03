<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import CommentBoard from '@/components/articles/CommentBoard.vue';
import { useAuth } from '@/composables/useAuth';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    ArticleApiError,
    deleteArticle,
    fetchAuthArticleDetail,
    fetchPublicArticleDetail,
} from '@/lib/articles-api';
import type { ArticleDetail } from '@/lib/articles-api';
import { routes } from '@/lib/routes';

const props = defineProps<{
    articleId: number;
}>();

const { t } = useI18n();

const article = ref<ArticleDetail | null>(null);
const isLoading = ref(false);
const isDeleting = ref(false);
const errorMessage = ref('');
const { user, isLoggedIn: isAuthenticated } = useAuth();
const isOwner = computed(
    () => !!user.value && user.value.id === article.value?.user_id,
);

const renderedContent = computed(() => article.value?.content || '');

function formatDate(isoDate: string | undefined) {
    if (!isoDate) {
        return '-';
    }

    const date = new Date(isoDate);

    if (Number.isNaN(date.getTime())) {
        return isoDate;
    }

    return date.toISOString().slice(0, 10).replace(/-/g, '.');
}

async function loadArticle() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        article.value = isAuthenticated.value
            ? await fetchAuthArticleDetail(props.articleId)
            : await fetchPublicArticleDetail(props.articleId);
    } catch (error: unknown) {
        errorMessage.value =
            error instanceof ArticleApiError
                ? error.message
                : t('articles.show.load_failed');
    } finally {
        isLoading.value = false;
    }
}

async function handleDeleteArticle() {
    if (!article.value || isDeleting.value) {
        return;
    }

    const confirmed = window.confirm(t('articles.show.confirm_delete'));

    if (!confirmed) {
        return;
    }

    isDeleting.value = true;

    try {
        await deleteArticle(article.value.id);
        router.visit(routes.articles.index());
    } catch (error: unknown) {
        errorMessage.value =
            error instanceof ArticleApiError
                ? error.message
                : t('articles.show.delete_failed');
    } finally {
        isDeleting.value = false;
    }
}

onMounted(async () => {
    await loadArticle();
});
</script>

<template>
    <Head :title="article?.title || t('articles.show.head_title_fallback')" />

    <AppLayout>
        <main class="pb-24">
            <section class="mx-auto max-w-screen-xl px-6 py-12 md:px-8">
                <div
                    v-if="isLoading"
                    class="py-20 text-center text-sm text-[var(--binary-text-muted)]"
                >
                    {{ t('articles.show.loading') }}
                </div>

                <p
                    v-else-if="errorMessage"
                    class="rounded-lg border border-red-400/20 bg-red-950/20 px-4 py-3 text-sm text-red-300"
                >
                    {{ errorMessage }}
                </p>

                <article
                    v-else-if="article"
                    class="binary-card-raised rounded-2xl"
                >
                    <div
                        class="mb-6 flex flex-wrap items-center justify-between gap-3"
                    >
                        <span
                            class="binary-label text-xs text-[var(--binary-outline)] uppercase"
                        >
                            {{ formatDate(article.created_at) }} /
                            {{
                                article.category ||
                                t('articles.show.uncategorized')
                            }}
                        </span>
                        <div v-if="isOwner" class="flex items-center gap-2">
                            <a
                                :href="routes.articles.edit(article.id)"
                                class="binary-ghost-button px-4 py-1.5 text-xs"
                            >
                                {{ t('articles.show.edit') }}
                            </a>
                            <button
                                class="binary-ghost-button border border-red-400/40 text-red-300 hover:bg-red-500/10 disabled:opacity-50"
                                :disabled="isDeleting"
                                type="button"
                                @click="handleDeleteArticle"
                            >
                                {{
                                    isDeleting
                                        ? t('articles.show.deleting')
                                        : t('articles.show.delete')
                                }}
                            </button>
                        </div>
                    </div>

                    <h1
                        class="binary-display mb-4 text-4xl font-black tracking-tight uppercase md:text-5xl"
                    >
                        {{ article.title || t('articles.show.untitled') }}
                    </h1>

                    <p
                        v-if="article.summary"
                        class="mb-6 text-sm text-[var(--binary-text-muted)]"
                    >
                        {{ article.summary }}
                    </p>

                    <img
                        v-if="article.image_url"
                        :src="article.image_url"
                        :alt="
                            article.title ||
                            t('articles.show.article_image_alt')
                        "
                        class="mb-8 w-full rounded-xl border border-[var(--binary-outline)]/20 object-cover"
                    />

                    <div
                        class="prose prose-invert max-w-none leading-relaxed whitespace-pre-wrap text-[var(--binary-text)]"
                    >
                        {{ renderedContent }}
                    </div>

                    <div
                        class="binary-label mt-8 flex flex-wrap gap-3 text-[10px] text-[var(--binary-outline)] uppercase"
                    >
                        <span v-for="tag in article.tags" :key="tag"
                            >#{{ tag }}</span
                        >
                    </div>

                    <CommentBoard :article-id="props.articleId" />
                </article>
            </section>
        </main>
    </AppLayout>
</template>

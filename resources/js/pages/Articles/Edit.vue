<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import { useAuth } from '@/composables/useAuth';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    ArticleApiError,
    fetchAuthArticleDetail,
    updateArticle,
} from '@/lib/articles-api';
import type { ArticleDetail } from '@/lib/articles-api';
import { routes } from '@/lib/routes';

const props = defineProps<{
    articleId: number;
}>();

const { t } = useI18n();

const article = ref<ArticleDetail | null>(null);
const isLoading = ref(false);
const isSaving = ref(false);
const errorMessage = ref('');
const { user } = useAuth();

const title = ref('');
const content = ref('');
const summary = ref('');
const tagsRaw = ref('');

async function loadArticle() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const loaded = await fetchAuthArticleDetail(props.articleId);
        const currentUserId = user.value?.id;

        if (!currentUserId || loaded.user_id !== currentUserId) {
            errorMessage.value = t('articles.edit.no_permission');
            article.value = null;
            router.visit(routes.articles.index());

            return;
        }

        article.value = loaded;
        title.value = loaded.title ?? '';
        content.value = loaded.content ?? '';
        summary.value = loaded.summary ?? '';
        tagsRaw.value = (loaded.tags ?? []).join(', ');
    } catch (error: unknown) {
        errorMessage.value =
            error instanceof ArticleApiError
                ? error.message
                : t('articles.edit.load_failed');
    } finally {
        isLoading.value = false;
    }
}

async function save() {
    if (isSaving.value) {
        return;
    }

    if (!article.value || article.value.user_id !== user.value?.id) {
        errorMessage.value = t('articles.edit.no_permission');
        router.visit(routes.articles.index());

        return;
    }

    isSaving.value = true;
    errorMessage.value = '';

    try {
        const tags = tagsRaw.value
            .split(',')
            .map((tag) => tag.trim())
            .filter(Boolean);

        await updateArticle(props.articleId, {
            title: title.value.trim() || null,
            content: content.value.trim() || null,
            summary: summary.value.trim() || null,
            tags,
        });
        router.visit(routes.articles.show(props.articleId));
    } catch (error: unknown) {
        errorMessage.value =
            error instanceof ArticleApiError
                ? error.message
                : t('articles.edit.save_failed');
    } finally {
        isSaving.value = false;
    }
}

onMounted(async () => {
    if (!user.value) {
        router.visit(routes.home());

        return;
    }

    await loadArticle();
});
</script>

<template>
    <Head
        :title="
            article?.title
                ? `${article.title} / ${t('articles.edit.head_title_suffix')}`
                : t('articles.edit.head_title_fallback')
        "
    />

    <AppLayout>
        <main class="pb-24">
            <section class="binary-section mx-auto max-w-screen-xl">
                <div
                    v-if="isLoading"
                    class="py-20 text-center text-sm text-[var(--binary-text-muted)]"
                >
                    {{ t('articles.edit.loading') }}
                </div>

                <p
                    v-else-if="errorMessage && !article"
                    class="rounded-lg border border-red-400/20 bg-red-950/20 px-4 py-3 text-sm text-red-300"
                >
                    {{ errorMessage }}
                </p>

                <template v-else-if="article">
                    <!-- 標題列 -->
                    <div class="mb-6">
                        <div
                            class="mb-1 flex items-center justify-between gap-3"
                        >
                            <h1 class="binary-page-title">
                                {{ t('articles.edit.title') }}
                            </h1>
                            <div class="flex gap-2">
                                <a
                                    :href="routes.articles.show(articleId)"
                                    class="binary-ghost-button px-4 py-1.5 text-xs"
                                >
                                    {{ t('articles.edit.back_to_show') }}
                                </a>
                            </div>
                        </div>
                        <p class="text-sm text-[var(--binary-text-muted)]">
                            {{ t('articles.edit.category') }}:
                            {{ article.category ?? '-' }}
                        </p>
                    </div>

                    <!-- 編輯表單 -->
                    <div class="binary-card-raised space-y-6">
                        <div>
                            <label
                                class="binary-label mb-1 block text-[10px] text-[var(--binary-outline)] uppercase"
                                >{{ t('articles.edit.field_title') }}</label
                            >
                            <input
                                v-model="title"
                                type="text"
                                maxlength="255"
                                :placeholder="
                                    t('articles.edit.title_placeholder')
                                "
                                class="binary-input w-full"
                            />
                        </div>

                        <div>
                            <label
                                class="binary-label mb-1 block text-[10px] text-[var(--binary-outline)] uppercase"
                                >{{ t('articles.edit.field_summary') }}</label
                            >
                            <textarea
                                v-model="summary"
                                rows="3"
                                maxlength="500"
                                :placeholder="
                                    t('articles.edit.summary_placeholder')
                                "
                                class="binary-input w-full resize-none"
                            />
                            <p
                                class="mt-1 text-right text-[10px] text-[var(--binary-text-muted)]"
                            >
                                {{ summary.length }} / 500
                            </p>
                        </div>

                        <div>
                            <label
                                class="binary-label mb-1 block text-[10px] text-[var(--binary-outline)] uppercase"
                            >
                                {{ t('articles.edit.field_tags') }}
                                <span class="normal-case">{{
                                    t('articles.edit.field_tags_hint')
                                }}</span>
                            </label>
                            <input
                                v-model="tagsRaw"
                                type="text"
                                :placeholder="
                                    t('articles.edit.tags_placeholder')
                                "
                                class="binary-input w-full"
                            />
                        </div>

                        <div>
                            <label
                                class="binary-label mb-1 block text-[10px] text-[var(--binary-outline)] uppercase"
                                >{{ t('articles.edit.field_content') }}</label
                            >
                            <textarea
                                v-model="content"
                                rows="20"
                                :placeholder="
                                    t('articles.edit.content_placeholder')
                                "
                                class="binary-input w-full resize-y font-mono text-sm"
                            />
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p
                                    v-if="errorMessage"
                                    class="text-xs text-red-400"
                                >
                                    {{ errorMessage }}
                                </p>
                            </div>
                            <button
                                class="binary-button"
                                :class="{
                                    'cursor-not-allowed opacity-50': isSaving,
                                }"
                                :disabled="isSaving"
                                type="button"
                                @click="save"
                            >
                                {{
                                    isSaving
                                        ? t('articles.edit.saving')
                                        : t('articles.edit.save')
                                }}
                            </button>
                        </div>
                    </div>
                </template>
            </section>
        </main>
    </AppLayout>
</template>

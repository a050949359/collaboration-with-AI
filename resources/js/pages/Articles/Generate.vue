<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import { useAuth } from '@/composables/useAuth';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    ArticleApiError,
    createArticle,
    fetchAuthArticleDetail,
    triggerGenerateContent,
    triggerGenerateImage,
} from '@/lib/articles-api';
import type { ArticleDetail } from '@/lib/articles-api';
import { routes } from '@/lib/routes';

// ── Enums（從後端 pageProps 注入） ────────────────────────
const TOPICS = computed(() => page.props.articleTopics);
const LANGUAGES = computed(() => page.props.articleLanguages);
const STYLES = computed(() => page.props.articleStyles);

const page = usePage<{
    articleAspectRatios: string[];
    articleTopics: string[];
    articleLanguages: string[];
    articleStyles: string[];
}>();
const ASPECT_RATIOS = computed(() => page.props.articleAspectRatios);
const PROMPT_MAX = 300;
const { t } = useI18n();

// ── State ────────────────────────────────────────────────
const article = ref<ArticleDetail | null>(null);
const currentArticleId = ref<number | null>(null);
const { user } = useAuth();

const topic = ref<string>('travel');
const language = ref<string>('zh-TW');
const style = ref<string>('practical');
const extraPrompt = ref<string>('');
const aspectRatio = ref<string>('16:9');

const isGeneratingContent = ref(false);
const isGeneratingImage = ref(false);
const contentMessage = ref('');
const imageMessage = ref('');

let pollTimer: ReturnType<typeof setInterval> | null = null;

// ── Computed ─────────────────────────────────────────────
const promptChars = computed(() => extraPrompt.value.length);
const promptOver = computed(() => promptChars.value > PROMPT_MAX);

const contentStatus = computed(() => article.value?.content_status ?? '');
const imageStatus = computed(() => article.value?.image_status ?? '');

const canGenerateContent = computed(
    () => !isGeneratingContent.value && contentStatus.value !== 'processing',
);
const canGenerateImage = computed(
    () =>
        !!currentArticleId.value &&
        !isGeneratingImage.value &&
        imageStatus.value !== 'processing',
);

// ── Polling ──────────────────────────────────────────────
function startPolling() {
    if (pollTimer) {
        return;
    }

    pollTimer = setInterval(async () => {
        const art = article.value;

        if (!art) {
            return;
        }

        const needsPoll =
            art.content_status === 'processing' ||
            art.image_status === 'processing';

        if (!needsPoll) {
            stopPolling();

            return;
        }

        try {
            if (!currentArticleId.value) {
                stopPolling();

                return;
            }

            const fresh = await fetchAuthArticleDetail(currentArticleId.value);
            article.value = fresh;

            if (fresh.content_status === 'completed') {
                contentMessage.value = t('articles.generate.content_ready');
                isGeneratingContent.value = false;
            } else if (fresh.content_status === 'failed') {
                contentMessage.value =
                    t('articles.generate.content_failed_prefix') +
                    (fresh.content_error ??
                        t('articles.generate.unknown_error'));
                isGeneratingContent.value = false;
            }

            if (fresh.image_status === 'completed') {
                imageMessage.value = t('articles.generate.image_ready');
                isGeneratingImage.value = false;
            } else if (fresh.image_status === 'failed') {
                imageMessage.value =
                    t('articles.generate.image_failed_prefix') +
                    (fresh.image_error ?? t('articles.generate.unknown_error'));
                isGeneratingImage.value = false;
            }
        } catch {
            // silent poll failure
        }
    }, 8000);
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

// ── Actions ──────────────────────────────────────────────
async function ensureArticle(): Promise<number | null> {
    if (currentArticleId.value) {
        return currentArticleId.value;
    }

    try {
        const created = await createArticle();
        currentArticleId.value = created.id;
        article.value = {
            ...created,
            prompt: null,
            content: null,
        };

        return created.id;
    } catch (error: unknown) {
        contentMessage.value =
            error instanceof ArticleApiError
                ? error.message
                : t('articles.generate.create_draft_failed');

        return null;
    }
}

async function generateContent() {
    if (!canGenerateContent.value || promptOver.value) {
        return;
    }

    isGeneratingContent.value = true;
    contentMessage.value = '';

    try {
        const articleId = await ensureArticle();

        if (!articleId) {
            isGeneratingContent.value = false;

            return;
        }

        article.value = await triggerGenerateContent(articleId, {
            topic: topic.value,
            language: language.value,
            style: style.value,
            prompt: extraPrompt.value.trim() || undefined,
        });
        contentMessage.value = t('articles.generate.queued');
        startPolling();
    } catch (error: unknown) {
        contentMessage.value =
            error instanceof ArticleApiError
                ? error.message
                : t('articles.generate.submit_retry');
        isGeneratingContent.value = false;
    }
}

async function generateImage() {
    if (!currentArticleId.value) {
        imageMessage.value = t('articles.generate.missing_article_id');

        return;
    }

    if (!canGenerateImage.value) {
        return;
    }

    isGeneratingImage.value = true;
    imageMessage.value = '';

    try {
        article.value = await triggerGenerateImage(
            currentArticleId.value,
            aspectRatio.value,
        );
        imageMessage.value = t('articles.generate.queued');
        startPolling();
    } catch (error: unknown) {
        if (error instanceof ArticleApiError && error.status === 404) {
            imageMessage.value = t('articles.generate.image_not_found');
        } else {
            imageMessage.value =
                error instanceof ArticleApiError
                    ? error.message
                    : t('articles.generate.submit_retry');
        }

        isGeneratingImage.value = false;
    }
}

// ── Lifecycle ────────────────────────────────────────────
onMounted(async () => {
    if (!user.value) {
        router.visit(routes.home());

        return;
    }

    if (
        article.value?.content_status === 'processing' ||
        article.value?.image_status === 'processing'
    ) {
        startPolling();
    }
});

onUnmounted(() => stopPolling());
</script>

<template>
    <Head :title="t('articles.generate.head_title')" />

    <AppLayout>
        <main class="pt-24 pb-24">
            <section class="mx-auto max-w-screen-xl px-6 py-12 md:px-8">
                <!-- 標題列 -->
                <div class="binary-card-raised mb-6 rounded-2xl">
                    <div class="mb-1 flex items-center justify-between gap-3">
                        <h1
                            class="binary-display text-3xl font-black tracking-tight uppercase md:text-4xl"
                        >
                            {{ t('articles.generate.title') }}
                        </h1>
                        <div class="flex gap-2">
                            <a
                                v-if="currentArticleId"
                                :href="routes.articles.edit(currentArticleId)"
                                class="binary-ghost-button px-4 py-1.5 text-xs"
                            >
                                {{ t('articles.generate.to_edit') }}
                            </a>
                            <a
                                v-if="currentArticleId"
                                :href="routes.articles.show(currentArticleId)"
                                class="binary-ghost-button px-4 py-1.5 text-xs"
                            >
                                {{ t('articles.generate.to_show') }}
                            </a>
                        </div>
                    </div>
                    <p class="text-sm text-[var(--binary-text-muted)]">
                        {{
                            article?.title ||
                            t('articles.generate.no_article_hint')
                        }}
                    </p>
                </div>

                <!-- ── 文章生成區 ─────────────────────── -->
                <div class="binary-card-raised mb-6 rounded-2xl">
                    <h2
                        class="binary-label mb-4 text-xs font-bold tracking-widest text-[var(--binary-outline)] uppercase"
                    >
                        {{ t('articles.generate.content_section') }}
                    </h2>

                    <div class="mb-4 grid gap-4 md:grid-cols-3">
                        <div>
                            <label
                                class="binary-label mb-1 block text-[10px] text-[var(--binary-outline)] uppercase"
                                >{{ t('articles.generate.topic') }}</label
                            >
                            <select v-model="topic" class="binary-input w-full">
                                <option v-for="v in TOPICS" :key="v" :value="v">
                                    {{
                                        t(
                                            `articles.generate.options.topics.${v}`,
                                        )
                                    }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label
                                class="binary-label mb-1 block text-[10px] text-[var(--binary-outline)] uppercase"
                                >{{ t('articles.generate.language') }}</label
                            >
                            <select
                                v-model="language"
                                class="binary-input w-full"
                            >
                                <option
                                    v-for="v in LANGUAGES"
                                    :key="v"
                                    :value="v"
                                >
                                    {{
                                        t(
                                            `articles.generate.options.languages.${v}`,
                                        )
                                    }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label
                                class="binary-label mb-1 block text-[10px] text-[var(--binary-outline)] uppercase"
                                >{{ t('articles.generate.style') }}</label
                            >
                            <select v-model="style" class="binary-input w-full">
                                <option v-for="v in STYLES" :key="v" :value="v">
                                    {{
                                        t(
                                            `articles.generate.options.styles.${v}`,
                                        )
                                    }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label
                            class="binary-label mb-1 block text-[10px] text-[var(--binary-outline)] uppercase"
                        >
                            {{ t('articles.generate.extra_prompt') }}
                            <span class="normal-case">{{
                                t('articles.generate.extra_prompt_suffix', {
                                    max: PROMPT_MAX,
                                })
                            }}</span>
                        </label>
                        <textarea
                            v-model="extraPrompt"
                            :maxlength="PROMPT_MAX"
                            rows="3"
                            :placeholder="
                                t('articles.generate.extra_prompt_placeholder')
                            "
                            class="binary-input w-full resize-none"
                            :class="{ 'border-red-500': promptOver }"
                        />
                        <p
                            class="mt-1 text-right text-[10px]"
                            :class="
                                promptOver
                                    ? 'text-red-400'
                                    : 'text-[var(--binary-text-muted)]'
                            "
                        >
                            {{ promptChars }} / {{ PROMPT_MAX }}
                        </p>
                    </div>

                    <div
                        v-if="contentStatus === 'processing'"
                        class="mb-3 flex items-center gap-2 text-xs text-[var(--binary-primary)]"
                    >
                        <span
                            class="inline-block h-2 w-2 animate-ping rounded-full bg-[var(--binary-primary)]"
                        ></span>
                        {{ t('articles.generate.generating_content') }}
                    </div>
                    <p
                        v-if="contentMessage"
                        class="mb-3 text-xs"
                        :class="
                            contentStatus === 'failed'
                                ? 'text-red-400'
                                : 'text-[var(--binary-primary)]'
                        "
                    >
                        {{ contentMessage }}
                    </p>

                    <button
                        class="binary-button"
                        :class="{
                            'cursor-not-allowed opacity-50':
                                !canGenerateContent || promptOver,
                        }"
                        :disabled="!canGenerateContent || promptOver"
                        type="button"
                        @click="generateContent"
                    >
                        {{
                            isGeneratingContent ||
                            contentStatus === 'processing'
                                ? t('articles.generate.generating_content')
                                : t('articles.generate.generate_article')
                        }}
                    </button>
                </div>

                <!-- ── 圖片生成區 ─────────────────────── -->
                <div class="binary-card-raised mb-6 rounded-2xl">
                    <h2
                        class="binary-label mb-4 text-xs font-bold tracking-widest text-[var(--binary-outline)] uppercase"
                    >
                        {{ t('articles.generate.image_section') }}
                    </h2>

                    <p class="mb-4 text-xs text-[var(--binary-text-muted)]">
                        {{ t('articles.generate.image_auto_hint') }}
                    </p>

                    <div class="mb-4 w-48">
                        <label
                            class="binary-label mb-1 block text-[10px] text-[var(--binary-outline)] uppercase"
                            >{{ t('articles.generate.aspect_ratio') }}</label
                        >
                        <select
                            v-model="aspectRatio"
                            class="binary-input w-full"
                        >
                            <option
                                v-for="r in ASPECT_RATIOS"
                                :key="r"
                                :value="r"
                            >
                                {{ r }}
                            </option>
                        </select>
                    </div>

                    <div
                        v-if="article?.image_url && imageStatus === 'completed'"
                        class="mb-4"
                    >
                        <img
                            :src="article.image_url"
                            :alt="
                                article?.title ??
                                t('articles.show.article_image_alt')
                            "
                            class="max-h-48 rounded-lg object-cover"
                        />
                    </div>

                    <div
                        v-if="imageStatus === 'processing'"
                        class="mb-3 flex items-center gap-2 text-xs text-[var(--binary-primary)]"
                    >
                        <span
                            class="inline-block h-2 w-2 animate-ping rounded-full bg-[var(--binary-primary)]"
                        ></span>
                        {{ t('articles.generate.generating_image') }}
                    </div>
                    <p
                        v-if="imageMessage"
                        class="mb-3 text-xs"
                        :class="
                            imageStatus === 'failed'
                                ? 'text-red-400'
                                : 'text-[var(--binary-primary)]'
                        "
                    >
                        {{ imageMessage }}
                    </p>

                    <button
                        class="binary-button"
                        :class="{
                            'cursor-not-allowed opacity-50': !canGenerateImage,
                        }"
                        :disabled="!canGenerateImage"
                        type="button"
                        @click="generateImage"
                    >
                        {{
                            !currentArticleId
                                ? t('articles.generate.generate_first')
                                : isGeneratingImage ||
                                    imageStatus === 'processing'
                                  ? t('articles.generate.generating_image')
                                  : t('articles.generate.generate_image')
                        }}
                    </button>
                </div>

                <!-- ── 內容預覽 ───────────────────────── -->
                <div class="binary-card-raised rounded-2xl">
                    <h2
                        class="binary-label mb-4 text-xs font-bold tracking-widest text-[var(--binary-outline)] uppercase"
                    >
                        {{ t('articles.generate.preview_section') }}
                    </h2>
                    <div
                        class="mb-2 flex flex-wrap gap-4 text-[10px] text-[var(--binary-text-muted)]"
                    >
                        <span
                            >{{ t('articles.generate.status_content') }}:
                            <span class="font-bold text-[var(--binary-text)]">{{
                                contentStatus ||
                                t('articles.generate.article_status_pending')
                            }}</span></span
                        >
                        <span
                            >{{ t('articles.generate.status_image') }}:
                            <span class="font-bold text-[var(--binary-text)]">{{
                                imageStatus ||
                                t('articles.generate.article_status_pending')
                            }}</span></span
                        >
                        <span
                            >{{ t('articles.generate.status_category') }}:
                            <span class="font-bold text-[var(--binary-text)]">{{
                                article?.category ??
                                t('articles.generate.article_status_pending')
                            }}</span></span
                        >
                    </div>
                    <textarea
                        class="binary-input min-h-56 w-full resize-y"
                        :value="
                            article?.content ??
                            t('articles.generate.no_content')
                        "
                        readonly
                    />
                </div>
            </section>
        </main>
    </AppLayout>
</template>

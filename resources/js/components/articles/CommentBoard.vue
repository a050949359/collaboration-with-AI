<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import { useAuth } from '@/composables/useAuth';
import {
    ArticleApiError,
    deleteComment,
    fetchComments,
    postComment,
    updateComment,
} from '@/lib/articles-api';
import type { ArticleComment } from '@/lib/articles-api';
import { routes } from '@/lib/routes';

const props = defineProps<{ articleId: number }>();

const { t } = useI18n();
const { isLoggedIn } = useAuth();

const comments = ref<ArticleComment[]>([]);
const isLoading = ref(false);

const newBody = ref('');
const newGuestName = ref('');
const isSubmitting = ref(false);
const submitError = ref('');

const replyingTo = ref<number | null>(null);
const replyBody = ref('');
const replyGuestName = ref('');
const isSubmittingReply = ref(false);

const editingId = ref<number | null>(null);
const editBody = ref('');
const editGuestName = ref('');
const isSavingEdit = ref(false);
const deleteError = ref('');

async function loadComments() {
    isLoading.value = true;

    try {
        comments.value = await fetchComments(props.articleId);
    } finally {
        isLoading.value = false;
    }
}

async function submitNewComment() {
    if (!newBody.value.trim()) {
        return;
    }

    isSubmitting.value = true;
    submitError.value = '';

    try {
        await postComment(props.articleId, {
            body: newBody.value,
            guest_name: isLoggedIn.value
                ? undefined
                : newGuestName.value.trim() || 'Guest',
        });
        newBody.value = '';
        newGuestName.value = '';
        await loadComments();
    } catch (e: unknown) {
        submitError.value =
            e instanceof ArticleApiError
                ? e.message
                : t('articles.comments.submit_failed');
    } finally {
        isSubmitting.value = false;
    }
}

async function submitReply(parentId: number) {
    if (!replyBody.value.trim()) {
        return;
    }

    isSubmittingReply.value = true;

    try {
        await postComment(props.articleId, {
            body: replyBody.value,
            guest_name: isLoggedIn.value
                ? undefined
                : replyGuestName.value.trim() || 'Guest',
            parent_id: parentId,
        });
        replyBody.value = '';
        replyGuestName.value = '';
        replyingTo.value = null;
        await loadComments();
    } finally {
        isSubmittingReply.value = false;
    }
}

function startEdit(comment: ArticleComment) {
    editingId.value = comment.id;
    editBody.value = comment.body;
    editGuestName.value = comment.guest_name ?? '';
}

async function saveEdit(comment: ArticleComment) {
    isSavingEdit.value = true;

    try {
        await updateComment(comment.id, {
            body: editBody.value,
            guest_name: comment.user_id ? undefined : editGuestName.value,
        });
        editingId.value = null;
        await loadComments();
    } finally {
        isSavingEdit.value = false;
    }
}

async function handleDelete(comment: ArticleComment) {
    if (!confirm(t('articles.comments.confirm_delete'))) {
        return;
    }

    deleteError.value = '';

    try {
        await deleteComment(comment.id);
        await loadComments();
    } catch (e: unknown) {
        deleteError.value =
            e instanceof ArticleApiError
                ? e.message
                : t('articles.comments.delete_failed');
    }
}

function authorName(comment: ArticleComment): string {
    return (
        comment.user?.name ??
        comment.guest_name ??
        t('articles.comments.anonymous')
    );
}

function avatarUrl(comment: ArticleComment): string | null {
    return comment.user ? routes.assets.avatarDefault(comment.user.name) : null;
}

function formatDate(iso: string): string {
    return new Date(iso).toISOString().slice(0, 10).replace(/-/g, '.');
}

onMounted(loadComments);
</script>

<template>
    <section class="mt-16">
        <h2
            class="binary-label mb-6 text-xs tracking-widest text-[var(--binary-outline)] uppercase"
        >
            {{ t('articles.comments.title') }}
            <span v-if="comments.length" class="ml-2 opacity-60"
                >({{ comments.length }})</span
            >
        </h2>

        <p
            v-if="deleteError"
            class="mb-4 rounded-lg border border-red-400/20 bg-red-950/20 px-4 py-2 text-xs text-red-300"
        >
            {{ deleteError }}
        </p>

        <!-- New comment form -->
        <div class="binary-card-raised mb-8 rounded-xl p-5">
            <div v-if="!isLoggedIn" class="mb-3">
                <input
                    v-model="newGuestName"
                    class="binary-input w-full"
                    :placeholder="t('articles.comments.guest_name_placeholder')"
                    maxlength="50"
                />
            </div>
            <textarea
                v-model="newBody"
                class="binary-input mb-3 w-full resize-none"
                rows="3"
                :placeholder="t('articles.comments.body_placeholder')"
                maxlength="2000"
            />
            <p v-if="submitError" class="mb-2 text-xs text-red-400">
                {{ submitError }}
            </p>
            <div class="flex justify-end">
                <button
                    class="binary-ghost-button px-4 py-1.5 text-xs disabled:opacity-40"
                    :disabled="isSubmitting || !newBody.trim()"
                    type="button"
                    @click="submitNewComment"
                >
                    {{
                        isSubmitting
                            ? t('articles.comments.submitting')
                            : t('articles.comments.submit')
                    }}
                </button>
            </div>
        </div>

        <!-- Loading -->
        <p v-if="isLoading" class="text-sm text-[var(--binary-text-muted)]">
            {{ t('articles.comments.loading') }}
        </p>

        <!-- Empty -->
        <p
            v-else-if="!isLoading && !comments.length"
            class="text-sm text-[var(--binary-text-muted)]"
        >
            {{ t('articles.comments.empty') }}
        </p>

        <!-- Comment list -->
        <div v-else class="space-y-4">
            <div
                v-for="comment in comments"
                :key="comment.id"
                class="binary-card-raised rounded-xl p-4"
            >
                <!-- Author row -->
                <div class="mb-2 flex items-center gap-2">
                    <img
                        v-if="avatarUrl(comment)"
                        :src="avatarUrl(comment)!"
                        class="h-6 w-6 rounded-full"
                        alt=""
                    />
                    <span
                        class="text-xs font-semibold text-[var(--binary-text)]"
                        >{{ authorName(comment) }}</span
                    >
                    <span class="text-[10px] text-[var(--binary-text-muted)]">{{
                        formatDate(comment.created_at)
                    }}</span>
                </div>

                <!-- Edit form -->
                <template v-if="editingId === comment.id">
                    <input
                        v-if="!comment.user_id"
                        v-model="editGuestName"
                        class="binary-input mb-2 w-full"
                        maxlength="50"
                    />
                    <textarea
                        v-model="editBody"
                        class="binary-input mb-2 w-full resize-none"
                        rows="3"
                        maxlength="2000"
                    />
                    <div class="flex gap-2">
                        <button
                            class="binary-ghost-button px-3 py-1 text-xs disabled:opacity-40"
                            :disabled="isSavingEdit"
                            type="button"
                            @click="saveEdit(comment)"
                        >
                            {{ t('articles.comments.save') }}
                        </button>
                        <button
                            class="binary-ghost-button px-3 py-1 text-xs opacity-60"
                            type="button"
                            @click="editingId = null"
                        >
                            {{ t('articles.comments.cancel') }}
                        </button>
                    </div>
                </template>

                <!-- Body -->
                <p
                    v-else
                    class="text-sm leading-relaxed text-[var(--binary-text)]"
                >
                    {{ comment.body }}
                </p>

                <!-- Actions -->
                <div class="mt-3 flex flex-wrap gap-4">
                    <button
                        class="binary-label text-[10px] text-[var(--binary-outline)] uppercase transition-colors hover:text-[var(--binary-text)]"
                        type="button"
                        @click="
                            replyingTo =
                                replyingTo === comment.id ? null : comment.id
                        "
                    >
                        {{ t('articles.comments.reply') }}
                    </button>
                    <template v-if="comment.can_edit">
                        <button
                            class="binary-label text-[10px] text-[var(--binary-outline)] uppercase transition-colors hover:text-[var(--binary-text)]"
                            type="button"
                            @click="startEdit(comment)"
                        >
                            {{ t('articles.comments.edit') }}
                        </button>
                        <button
                            class="binary-label text-[10px] text-red-400/60 uppercase transition-colors hover:text-red-300"
                            type="button"
                            @click="handleDelete(comment)"
                        >
                            {{ t('articles.comments.delete') }}
                        </button>
                    </template>
                </div>

                <!-- Inline reply form -->
                <div
                    v-if="replyingTo === comment.id"
                    class="mt-4 border-l-2 border-[var(--binary-outline)]/20 pl-4"
                >
                    <input
                        v-if="!isLoggedIn"
                        v-model="replyGuestName"
                        class="binary-input mb-2 w-full"
                        :placeholder="
                            t('articles.comments.guest_name_placeholder')
                        "
                        maxlength="50"
                    />
                    <textarea
                        v-model="replyBody"
                        class="binary-input mb-2 w-full resize-none"
                        rows="2"
                        :placeholder="t('articles.comments.reply_placeholder')"
                        maxlength="2000"
                    />
                    <div class="flex gap-2">
                        <button
                            class="binary-ghost-button px-3 py-1 text-xs disabled:opacity-40"
                            :disabled="isSubmittingReply || !replyBody.trim()"
                            type="button"
                            @click="submitReply(comment.id)"
                        >
                            {{ t('articles.comments.submit') }}
                        </button>
                        <button
                            class="binary-ghost-button px-3 py-1 text-xs opacity-60"
                            type="button"
                            @click="
                                replyingTo = null;
                                replyBody = '';
                            "
                        >
                            {{ t('articles.comments.cancel') }}
                        </button>
                    </div>
                </div>

                <!-- Replies -->
                <div
                    v-if="comment.children?.length"
                    class="mt-4 space-y-4 border-l-2 border-[var(--binary-outline)]/20 pl-4"
                >
                    <div v-for="reply in comment.children" :key="reply.id">
                        <div class="mb-1 flex items-center gap-2">
                            <img
                                v-if="avatarUrl(reply)"
                                :src="avatarUrl(reply)!"
                                class="h-5 w-5 rounded-full"
                                alt=""
                            />
                            <span
                                class="text-xs font-semibold text-[var(--binary-text)]"
                                >{{ authorName(reply) }}</span
                            >
                            <span
                                class="text-[10px] text-[var(--binary-text-muted)]"
                                >{{ formatDate(reply.created_at) }}</span
                            >
                        </div>

                        <!-- Edit form for reply -->
                        <template v-if="editingId === reply.id">
                            <input
                                v-if="!reply.user_id"
                                v-model="editGuestName"
                                class="binary-input mb-2 w-full"
                                maxlength="50"
                            />
                            <textarea
                                v-model="editBody"
                                class="binary-input mb-2 w-full resize-none"
                                rows="2"
                                maxlength="2000"
                            />
                            <div class="flex gap-2">
                                <button
                                    class="binary-ghost-button px-3 py-1 text-xs disabled:opacity-40"
                                    :disabled="isSavingEdit"
                                    type="button"
                                    @click="saveEdit(reply)"
                                >
                                    {{ t('articles.comments.save') }}
                                </button>
                                <button
                                    class="binary-ghost-button px-3 py-1 text-xs opacity-60"
                                    type="button"
                                    @click="editingId = null"
                                >
                                    {{ t('articles.comments.cancel') }}
                                </button>
                            </div>
                        </template>

                        <p
                            v-else
                            class="text-sm leading-relaxed text-[var(--binary-text)]"
                        >
                            {{ reply.body }}
                        </p>

                        <div v-if="reply.can_edit" class="mt-2 flex gap-4">
                            <button
                                class="binary-label text-[10px] text-[var(--binary-outline)] uppercase transition-colors hover:text-[var(--binary-text)]"
                                type="button"
                                @click="startEdit(reply)"
                            >
                                {{ t('articles.comments.edit') }}
                            </button>
                            <button
                                class="binary-label text-[10px] text-red-400/60 uppercase transition-colors hover:text-red-300"
                                type="button"
                                @click="handleDelete(reply)"
                            >
                                {{ t('articles.comments.delete') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

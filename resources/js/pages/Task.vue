<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuth } from '../composables/useAuth';
import AppLayout from '../layouts/AppLayout.vue';
import { api } from '../lib/routes';

const { t } = useI18n();
const { user } = useAuth();
const page = usePage<{ taskStatuses: string[] }>();

const isOwner = (task: { created_by: number }) =>
    !!user.value && user.value.id === task.created_by;

type TaskStatus = string;

interface TaskItem {
    id: number;
    task_id: number;
    content: string;
    is_done: boolean;
    sort: number;
}

interface Task {
    id: number;
    title: string;
    description: string | null;
    status: TaskStatus;
    sort: number;
    created_by: number;
    items: TaskItem[];
}

const tasks = ref<Task[]>([]);
const loading = ref(false);
const expandedIds = ref<Set<number>>(new Set());

// 編輯 task
const editingId = ref<number | null>(null);
const taskStatuses = computed(() => page.props.taskStatuses);
const editForm = ref({
    title: '',
    description: '',
    status: taskStatuses.value[0] as TaskStatus,
});

const statusLabels = computed<Record<TaskStatus, string>>(() => ({
    todo: t('task.status_todo'),
    in_progress: t('task.status_in_progress'),
    done: t('task.status_done'),
}));

const statusColors: Record<TaskStatus, string> = {
    todo: 'text-[var(--binary-outline)] border-[var(--binary-outline)]/30',
    in_progress: 'text-yellow-400 border-yellow-500/30',
    done: 'text-green-400 border-green-500/30',
};

type FilterKey = TaskStatus | 'all' | 'no_items';

const activeFilter = ref<FilterKey>('all');

interface FilterTab {
    key: FilterKey;
    label: string;
    count: number;
    activeClass: string;
}

const filterTabs = computed<FilterTab[]>(() => [
    {
        key: 'all',
        label: t('task.filter_all'),
        count: tasks.value.length,
        activeClass:
            'text-[var(--binary-primary)] border-[var(--binary-primary)]/60',
    },
    ...taskStatuses.value.map((s) => ({
        key: s as FilterKey,
        label: statusLabels.value[s],
        count: tasks.value.filter((t) => t.status === s).length,
        activeClass: statusColors[s],
    })),
    {
        key: 'no_items' as FilterKey,
        label: t('task.filter_no_items'),
        count: tasks.value.filter((t) => t.items.length === 0).length,
        activeClass:
            'text-[var(--binary-secondary)] border-[var(--binary-secondary)]/40',
    },
]);

const filteredTasks = computed(() => {
    if (activeFilter.value === 'all') {
        return tasks.value;
    }

    if (activeFilter.value === 'no_items') {
        return tasks.value.filter((t) => t.items.length === 0);
    }

    return tasks.value.filter((t) => t.status === activeFilter.value);
});

async function fetchTasks() {
    loading.value = true;

    try {
        const res = await fetch(api.tasks.index(), { credentials: 'include' });
        tasks.value = await res.json();
    } finally {
        loading.value = false;
    }
}

function toggleExpand(id: number) {
    if (expandedIds.value.has(id)) {
        expandedIds.value.delete(id);
    } else {
        expandedIds.value.add(id);
    }
}

function startEdit(task: Task) {
    editingId.value = task.id;
    editForm.value = {
        title: task.title,
        description: task.description ?? '',
        status: task.status,
    };
}

async function saveEdit(task: Task) {
    const res = await fetch(api.tasks.update(task.id), {
        method: 'PATCH',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(editForm.value),
    });

    if (res.ok) {
        const updated: Task = await res.json();
        const idx = tasks.value.findIndex((t) => t.id === task.id);

        if (idx !== -1) {
            tasks.value[idx] = updated;
        }

        editingId.value = null;
    }
}

async function toggleItem(task: Task, item: TaskItem) {
    const res = await fetch(api.tasks.itemUpdate(task.id, item.id), {
        method: 'PATCH',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ is_done: !item.is_done }),
    });

    if (res.ok) {
        item.is_done = !item.is_done;
    }
}

onMounted(fetchTasks);
</script>

<template>
    <Head :title="t('task.head_title')" />
    <AppLayout>
        <div
            class="mx-auto w-full max-w-screen-2xl px-[18px] pt-8 pb-16 md:px-8"
        >
            <p
                class="binary-label mb-2 text-xs font-bold text-[var(--binary-primary)] uppercase"
            >
                &gt; {{ t('task.breadcrumb') }}
            </p>
            <h1 class="binary-page-title mb-8 text-[var(--binary-text)]">
                TASKS
            </h1>

            <!-- Filter tabs -->
            <div class="mb-8 flex flex-wrap gap-2">
                <button
                    v-for="tab in filterTabs"
                    :key="tab.key"
                    class="flex items-center gap-2 rounded-lg border px-4 py-2 text-xs font-bold tracking-wider uppercase transition-colors"
                    :class="
                        activeFilter === tab.key
                            ? tab.activeClass
                            : 'border-[var(--binary-outline-variant)] text-[var(--binary-text-muted)] hover:border-[var(--binary-outline)]/50 hover:text-[var(--binary-text)]'
                    "
                    @click="activeFilter = tab.key"
                >
                    <span>{{ tab.label }}</span>
                    <span class="text-lg font-black">{{ tab.count }}</span>
                </button>
            </div>

            <!-- Task 清單 -->
            <div v-if="loading" class="text-xs opacity-50">
                {{ t('common.loading') }}
            </div>
            <div v-else class="space-y-3">
                <div
                    v-for="task in filteredTasks"
                    :key="task.id"
                    class="overflow-hidden rounded-none border bg-[var(--binary-surface-container)] md:rounded-xl"
                    :class="
                        task.status === 'done'
                            ? 'border-green-500/20 opacity-70'
                            : 'border-[var(--binary-outline-variant)]'
                    "
                >
                    <!-- Task 標頭 -->
                    <div
                        class="flex cursor-pointer items-center gap-3 px-4 py-3 select-none"
                        @click="toggleExpand(task.id)"
                    >
                        <!-- 展開箭頭 -->
                        <span
                            class="text-xs text-[var(--binary-outline)] transition-transform"
                            :class="expandedIds.has(task.id) ? 'rotate-90' : ''"
                            >▶</span
                        >

                        <!-- 編輯模式 -->
                        <template v-if="editingId === task.id">
                            <div
                                class="flex flex-1 items-center gap-2"
                                @click.stop
                            >
                                <input
                                    v-model="editForm.title"
                                    class="binary-input flex-1 text-sm"
                                    @keydown.enter="saveEdit(task)"
                                    @keydown.esc="editingId = null"
                                />
                                <select
                                    v-model="editForm.status"
                                    class="binary-input w-32 shrink-0 text-xs"
                                >
                                    <option
                                        v-for="(label, val) in statusLabels"
                                        :key="val"
                                        :value="val"
                                    >
                                        {{ label }}
                                    </option>
                                </select>
                                <button
                                    class="text-xs text-[var(--binary-primary)] hover:opacity-80"
                                    @click.stop="saveEdit(task)"
                                >
                                    {{ t('task.btn_save') }}
                                </button>
                                <button
                                    class="text-xs text-[var(--binary-outline)] hover:text-[var(--binary-text)]"
                                    @click.stop="editingId = null"
                                >
                                    {{ t('task.btn_cancel') }}
                                </button>
                            </div>
                        </template>

                        <!-- 顯示模式 -->
                        <template v-else>
                            <span
                                class="flex-1 text-sm font-medium text-[var(--binary-text)]"
                                :class="
                                    task.status === 'done'
                                        ? 'line-through opacity-60'
                                        : ''
                                "
                                >{{ task.title }}</span
                            >
                            <span
                                class="shrink-0 rounded border px-2 py-0.5 text-[10px] font-bold tracking-wider uppercase"
                                :class="statusColors[task.status]"
                                >{{ statusLabels[task.status] }}</span
                            >
                            <span
                                class="shrink-0 text-[10px] text-[var(--binary-outline)]"
                            >
                                {{
                                    task.items.filter((i) => i.is_done).length
                                }}/{{ task.items.length }}
                            </span>
                            <button
                                v-if="isOwner(task)"
                                class="shrink-0 px-1 text-xs text-[var(--binary-outline)] hover:text-[var(--binary-text)]"
                                @click.stop="startEdit(task)"
                            >
                                ✎
                            </button>
                        </template>
                    </div>

                    <!-- 展開內容 -->
                    <div
                        v-if="expandedIds.has(task.id)"
                        class="space-y-2 border-t border-[var(--binary-outline-variant)]/40 px-4 py-3"
                    >
                        <!-- description -->
                        <template v-if="editingId === task.id">
                            <textarea
                                v-model="editForm.description"
                                class="binary-input h-16 w-full resize-none text-xs"
                                :placeholder="t('task.placeholder_desc')"
                            />
                        </template>
                        <p
                            v-else-if="task.description"
                            class="text-xs whitespace-pre-wrap text-[var(--binary-outline)]"
                        >
                            {{ task.description }}
                        </p>

                        <!-- Items -->
                        <div class="mt-2 space-y-1">
                            <div
                                v-for="item in task.items"
                                :key="item.id"
                                class="group flex items-center gap-2"
                            >
                                <button
                                    class="flex h-4 w-4 shrink-0 items-center justify-center rounded border transition-colors"
                                    :class="[
                                        item.is_done
                                            ? 'border-green-500/40 bg-green-500/20 text-green-400'
                                            : 'border-[var(--binary-outline)]/40',
                                        isOwner(task)
                                            ? 'cursor-pointer hover:border-[var(--binary-primary)]'
                                            : 'cursor-default',
                                    ]"
                                    :disabled="!isOwner(task)"
                                    @click="
                                        isOwner(task) && toggleItem(task, item)
                                    "
                                >
                                    <span
                                        v-if="item.is_done"
                                        class="text-[10px]"
                                        >✓</span
                                    >
                                </button>
                                <span
                                    class="flex-1 text-xs text-[var(--binary-text)]"
                                    :class="
                                        item.is_done
                                            ? 'line-through opacity-50'
                                            : ''
                                    "
                                    >{{ item.content }}</span
                                >
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <p
                v-if="!loading && tasks.length === 0"
                class="mt-6 text-xs text-[var(--binary-outline)]"
            >
                {{ t('task.empty') }}
            </p>
        </div>
    </AppLayout>
</template>

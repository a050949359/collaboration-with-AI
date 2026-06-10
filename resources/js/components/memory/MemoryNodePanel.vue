<script setup lang="ts">
// host 節點 typed observation 編輯面板（admin）。點 globe 上的 host 開啟。
// 讀 entities/{id}/typed → 列出/編輯/刪除；新增依各 type 的 maxCount 過濾下拉。
// 任何寫入後 emit changed，讓父層重抓 geo 刷新球體。
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { api } from '../../lib/routes';

interface TypedObs {
    id: number;
    type: string;
    content: string;
}
interface TypeOpt {
    value: string;
    maxCount: number | null;
}

const props = defineProps<{ entityId: number; entityName: string }>();
const emit = defineEmits<{ close: []; changed: [] }>();

const page = usePage();
const typeOpts = computed<TypeOpt[]>(
    () => (page.props.observationTypes as TypeOpt[]) ?? [],
);

const observations = ref<TypedObs[]>([]);
const loading = ref(false);
const error = ref('');
const newType = ref('');
const newContent = ref('');

// 依 maxCount 過濾還能新增的 type
const addableTypes = computed(() =>
    typeOpts.value.filter((t) => {
        if (t.maxCount === null) {
            return true;
        }

        const count = observations.value.filter(
            (o) => o.type === t.value,
        ).length;

        return count < t.maxCount;
    }),
);

async function load() {
    loading.value = true;
    error.value = '';

    try {
        const res = await fetch(api.memory.typed(props.entityId));

        if (!res.ok) {
            throw new Error(String(res.status));
        }

        const data = await res.json();
        observations.value = data.observations ?? [];
    } catch {
        error.value = '載入失敗（需 admin 權限）';
    } finally {
        loading.value = false;
    }
}

async function send(
    url: string,
    method: string,
    body?: object,
): Promise<boolean> {
    error.value = '';
    const res = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    if (!res.ok) {
        const j = await res.json().catch(() => ({}));
        error.value = j.message ?? `操作失敗（${res.status}）`;

        return false;
    }

    return true;
}

async function add() {
    if (!newType.value || !newContent.value.trim()) {
        return;
    }

    const ok = await send(api.memory.observationStore(), 'POST', {
        entity_id: props.entityId,
        type: newType.value,
        content: newContent.value.trim(),
    });

    if (ok) {
        newType.value = '';
        newContent.value = '';
        await load();
        emit('changed');
    }
}

async function save(o: TypedObs) {
    if (
        await send(api.memory.observationUpdate(o.id), 'PUT', {
            content: o.content,
        })
    ) {
        emit('changed');
    }
}

async function remove(o: TypedObs) {
    if (await send(api.memory.observationDestroy(o.id), 'DELETE')) {
        await load();
        emit('changed');
    }
}

watch(() => props.entityId, load);
onMounted(load);
</script>

<template>
    <div
        class="binary-glass flex w-72 flex-col gap-3 rounded-xl border border-[var(--binary-outline-variant)] p-4 text-[var(--binary-text)]"
    >
        <div class="flex items-center justify-between">
            <div class="min-w-0">
                <p
                    class="binary-label text-[10px] text-[var(--binary-outline)] uppercase"
                >
                    Host
                </p>
                <p class="truncate text-sm font-bold">{{ entityName }}</p>
            </div>
            <button
                class="text-[var(--binary-outline)] transition-colors hover:text-[var(--binary-text)]"
                aria-label="關閉"
                @click="emit('close')"
            >
                ✕
            </button>
        </div>

        <p v-if="error" class="text-xs" style="color: var(--binary-tertiary)">
            {{ error }}
        </p>
        <p v-if="loading" class="text-xs text-[var(--binary-outline)]">
            載入中…
        </p>

        <!-- 既有 typed 觀察 -->
        <div
            v-for="o in observations"
            :key="o.id"
            class="flex flex-col gap-1 rounded-lg p-2"
            style="background: var(--binary-surface-lowest)"
        >
            <span
                class="binary-label text-[10px] text-[var(--binary-primary)] uppercase"
                >{{ o.type }}</span
            >
            <div class="flex items-center gap-1">
                <input
                    v-model="o.content"
                    class="min-w-0 flex-1 rounded px-2 py-1 text-xs"
                    style="background: var(--binary-surface-low); outline: none"
                />
                <button
                    class="shrink-0 rounded px-2 py-1 text-[10px] text-[var(--binary-primary)] hover:bg-[var(--binary-primary)]/10"
                    @click="save(o)"
                >
                    存
                </button>
                <button
                    class="shrink-0 rounded px-2 py-1 text-[10px]"
                    style="color: var(--binary-tertiary)"
                    @click="remove(o)"
                >
                    刪
                </button>
            </div>
        </div>

        <p
            v-if="!loading && !observations.length"
            class="text-xs text-[var(--binary-outline)]"
        >
            尚無 typed 觀察
        </p>

        <!-- 新增 -->
        <div
            v-if="addableTypes.length"
            class="flex flex-col gap-1 border-t border-[var(--binary-outline-variant)] pt-3"
        >
            <div class="flex items-center gap-1">
                <select
                    v-model="newType"
                    class="rounded px-2 py-1 text-xs"
                    style="background: var(--binary-surface-low); outline: none"
                >
                    <option value="" disabled>type</option>
                    <option
                        v-for="t in addableTypes"
                        :key="t.value"
                        :value="t.value"
                    >
                        {{ t.value }}
                    </option>
                </select>
                <input
                    v-model="newContent"
                    placeholder="lat,lng"
                    class="min-w-0 flex-1 rounded px-2 py-1 text-xs"
                    style="background: var(--binary-surface-low); outline: none"
                />
                <button
                    class="shrink-0 rounded px-2 py-1 text-[10px] text-[var(--binary-primary)] hover:bg-[var(--binary-primary)]/10"
                    @click="add"
                >
                    新增
                </button>
            </div>
        </div>
    </div>
</template>

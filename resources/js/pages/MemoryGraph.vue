<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';
import { api } from '../lib/routes';

interface Entity {
    id: number;
    name: string;
    type: string;
    observations: string[];
}
interface Relation {
    from: string;
    relation_type: string;
    to: string;
}

const entities = ref<Entity[]>([]);
const relations = ref<Relation[]>([]);
const loading = ref(true);
const search = ref('');

async function fetchGraph() {
    loading.value = true;
    try {
        const res = await fetch(api.memoryGraph());
        const data = await res.json();
        entities.value = data.entities;
        relations.value = data.relations;
    } finally {
        loading.value = false;
    }
}

onMounted(fetchGraph);

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return entities.value;
    return entities.value.filter(e =>
        e.name.toLowerCase().includes(q) ||
        e.type.toLowerCase().includes(q) ||
        e.observations.some(o => o.toLowerCase().includes(q))
    );
});

function relationsOf(name: string) {
    return relations.value.filter(r => r.from === name || r.to === name);
}
</script>

<template>
    <Head title="Knowledge Graph" />
    <AppLayout>
        <div class="max-w-4xl mx-auto px-4 py-8">
            <div class="flex items-center justify-between mb-6">
                <h1 class="text-xl font-bold text-[var(--binary-text)]">Knowledge Graph</h1>
                <button class="text-xs text-[var(--binary-outline)] hover:text-[var(--binary-text)] transition-colors" @click="fetchGraph">↺ 重新整理</button>
            </div>

            <!-- Search -->
            <input
                v-model="search"
                class="binary-input w-full text-sm mb-6"
                placeholder="搜尋節點名稱、類型或觀察內容…"
            />

            <!-- Loading -->
            <div v-if="loading" class="text-xs text-[var(--binary-outline)] text-center py-12">載入中…</div>

            <!-- Empty -->
            <div v-else-if="!entities.length" class="text-xs text-[var(--binary-outline)] text-center py-12">尚無資料</div>

            <!-- Entities -->
            <div v-else class="space-y-3">
                <div
                    v-for="entity in filtered"
                    :key="entity.id"
                    class="rounded-xl border border-[var(--binary-outline-variant)] bg-[var(--binary-surface)] p-4"
                >
                    <!-- Header -->
                    <div class="flex items-center gap-2 mb-3">
                        <span class="text-base font-bold text-[var(--binary-text)]">{{ entity.name }}</span>
                        <span class="text-xs font-mono px-2 py-0.5 rounded bg-[var(--binary-primary)]/10 text-[var(--binary-primary)] border border-[var(--binary-primary)]/20">{{ entity.type }}</span>
                    </div>

                    <!-- Observations -->
                    <ul v-if="entity.observations.length" class="space-y-1 mb-3">
                        <li
                            v-for="(obs, i) in entity.observations"
                            :key="i"
                            class="text-xs text-[var(--binary-text-variant)] flex gap-2"
                        >
                            <span class="text-[var(--binary-outline)] shrink-0">·</span>
                            <span>{{ obs }}</span>
                        </li>
                    </ul>

                    <!-- Relations -->
                    <div v-if="relationsOf(entity.name).length" class="flex flex-wrap gap-2">
                        <span
                            v-for="(rel, i) in relationsOf(entity.name)"
                            :key="i"
                            class="text-xs font-mono px-2 py-0.5 rounded border border-[var(--binary-outline-variant)] text-[var(--binary-outline)]"
                        >
                            <template v-if="rel.from === entity.name">
                                → <span class="text-[var(--binary-primary)]">{{ rel.relation_type }}</span> → {{ rel.to }}
                            </template>
                            <template v-else>
                                ← <span class="text-purple-400">{{ rel.relation_type }}</span> ← {{ rel.from }}
                            </template>
                        </span>
                    </div>
                </div>

                <div v-if="search && !filtered.length" class="text-xs text-[var(--binary-outline)] text-center py-8">
                    找不到符合「{{ search }}」的節點
                </div>
            </div>
        </div>
    </AppLayout>
</template>

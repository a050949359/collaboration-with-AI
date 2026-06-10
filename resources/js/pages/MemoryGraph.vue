<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import MemoryGlobe from '../components/memory/MemoryGlobe.vue';
import MemoryNodePanel from '../components/memory/MemoryNodePanel.vue';
import { useAuth } from '../composables/useAuth';
import AppLayout from '../layouts/AppLayout.vue';
import { api } from '../lib/routes';
import type { GraphData } from '../lib/topology';
import {
    TYPE_COLOR,
    drawGraph as drawGraphImpl,
    drawTopology as drawTopologyImpl,
} from '../lib/topology';

const tab = ref<'graph' | 'topology' | 'globe'>('graph');
const svgRef = ref<SVGSVGElement | null>(null);
const topoSvgRef = ref<SVGSVGElement | null>(null);
const loading = ref(true);
const geoPoints = ref<{ entity: string; content: string }[]>([]);
const relations = ref<{ from: string; relation_type: string; to: string }[]>(
    [],
);
const selectedHost = ref<string | null>(null);
const entities = ref<{ id: number; name: string; type: string }[]>([]);
let graphData: GraphData = { entities: [], relations: [] };

const { isAdmin } = useAuth();
const selectedEntityId = computed(
    () => entities.value.find((e) => e.name === selectedHost.value)?.id ?? null,
);
// 所有 host（含尚無 geo 者）：供 admin 從清單選取填座標，解決「無 pin 點不到」
const hosts = computed(() => entities.value.filter((e) => e.type === 'host'));
const geoSet = computed(() => new Set(geoPoints.value.map((p) => p.entity)));

let simulation: ReturnType<typeof drawGraphImpl> = null;

// ── Graph view ───────────────────────────────────────────────────

function drawGraph(data: GraphData) {
    simulation?.stop();

    if (!svgRef.value) {
        return;
    }

    simulation = drawGraphImpl(svgRef.value, data, {
        fallbackW: 800,
        fallbackH: 560,
        markerId: 'arrow',
        markerSize: 6,
        arrowFill: 'var(--binary-outline)',
        linkStrokeWidth: 1.5,
        linkOpacity: 1,
        relFont: 9,
        relOpacity: 1,
        nodeRadiusBase: 12,
        nodeRadiusMul: 3,
        nodeFont: 11,
        nodeDy: 4,
        linkDistanceBase: 80,
        linkDistancePerRel: 40,
        chargeStrength: -600,
        forceStrength: 0.05,
        edgeGap: 6,
    });
}

// ── Topology view ────────────────────────────────────────────────

function drawTopology(data: GraphData) {
    if (!topoSvgRef.value) {
        return;
    }

    drawTopologyImpl(topoSvgRef.value, data, {
        fallbackW: 800,
        fallbackH: 560,
        pad: 14,
        projH: 28,
        projW: 150,
        rowGap: 80,
        boxGap: 40,
        markerId: 'ta',
        hostRx: 10,
        projRx: 5,
        hubR: 24,
        hostFont: 10,
        projFont: 9,
        relFont: 8,
    });
}

// ── Data fetch ───────────────────────────────────────────────────

async function fetchAndRender() {
    loading.value = true;
    const [graphRes, geoRes] = await Promise.all([
        fetch(api.memory.graph()),
        fetch(api.memory.geo()),
    ]);
    graphData = await graphRes.json();
    geoPoints.value = await geoRes.json();
    relations.value = graphData.relations;
    entities.value = graphData.entities;
    loading.value = false;
    await nextTick();

    if (tab.value === 'graph') {
        drawGraph(graphData);
    } else if (tab.value === 'topology') {
        drawTopology(graphData);
    }
}

async function refreshGeo() {
    const res = await fetch(api.memory.geo());
    geoPoints.value = await res.json();
}

function onHostClick(entity: string) {
    // 編輯面板僅 admin；非 admin 點 host 不開啟
    if (!isAdmin.value) {
        return;
    }

    selectedHost.value = entity;
}

watch(tab, async () => {
    simulation?.stop();
    await nextTick();

    if (tab.value === 'graph') {
        drawGraph(graphData);
    } else if (tab.value === 'topology') {
        drawTopology(graphData);
    }
});

onMounted(fetchAndRender);
onUnmounted(() => simulation?.stop());
</script>

<template>
    <Head title="Knowledge Graph" />
    <AppLayout>
        <div class="flex flex-col" style="height: calc(100vh - 4rem)">
            <!-- Header -->
            <div
                class="flex shrink-0 items-center justify-between border-b border-[var(--binary-outline-variant)] px-[18px] py-3 md:px-6"
            >
                <div class="flex items-center gap-4">
                    <h1 class="text-base font-bold text-[var(--binary-text)]">
                        Knowledge Graph
                    </h1>
                    <!-- Tabs -->
                    <div class="flex gap-1">
                        <button
                            v-for="t in ['graph', 'topology', 'globe'] as const"
                            :key="t"
                            class="rounded px-3 py-1 text-xs transition-colors"
                            :class="
                                tab === t
                                    ? 'bg-[var(--binary-primary)]/15 text-[var(--binary-primary)]'
                                    : 'text-[var(--binary-outline)] hover:text-[var(--binary-text)]'
                            "
                            @click="tab = t"
                        >
                            {{
                                {
                                    graph: 'Graph',
                                    topology: 'Topology',
                                    globe: 'Globe',
                                }[t]
                            }}
                        </button>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <div
                        v-if="tab === 'graph'"
                        class="hidden items-center gap-3 text-xs text-[var(--binary-outline)] md:flex"
                    >
                        <span
                            v-for="(color, type) in TYPE_COLOR"
                            :key="type"
                            class="flex items-center gap-1"
                        >
                            <span
                                class="h-2.5 w-2.5 rounded-full border"
                                :style="`background:${color}22;border-color:${color}`"
                            ></span>
                            {{ type }}
                        </span>
                    </div>
                    <button
                        class="text-xs text-[var(--binary-outline)] transition-colors hover:text-[var(--binary-text)]"
                        @click="fetchAndRender"
                    >
                        ↺
                    </button>
                </div>
            </div>

            <!-- Graph -->
            <div v-show="tab === 'graph'" class="relative min-h-0 flex-1">
                <div
                    v-if="loading"
                    class="absolute inset-0 flex items-center justify-center text-xs text-[var(--binary-outline)]"
                >
                    載入中…
                </div>
                <svg ref="svgRef" class="h-full w-full" />
            </div>

            <!-- Topology -->
            <div v-show="tab === 'topology'" class="relative min-h-0 flex-1">
                <div
                    v-if="loading"
                    class="absolute inset-0 flex items-center justify-center text-xs text-[var(--binary-outline)]"
                >
                    載入中…
                </div>
                <svg ref="topoSvgRef" class="h-full w-full" />
            </div>

            <!-- Globe -->
            <div
                v-show="tab === 'globe'"
                class="relative min-h-0 flex-1 p-3 md:p-4"
            >
                <MemoryGlobe
                    :points="geoPoints"
                    :relations="relations"
                    @host-click="onHostClick"
                />
                <!-- host 清單（admin）：含尚無 geo 者，點選即可開面板新增座標 -->
                <div
                    v-if="isAdmin && hosts.length"
                    class="binary-glass absolute top-6 left-6 z-10 max-h-[80%] w-44 overflow-y-auto rounded-xl border border-[var(--binary-outline-variant)] p-2"
                >
                    <p
                        class="binary-label px-2 py-1 text-[10px] text-[var(--binary-outline)] uppercase"
                    >
                        Hosts
                    </p>
                    <button
                        v-for="h in hosts"
                        :key="h.id"
                        class="flex w-full items-center gap-2 rounded px-2 py-1 text-left text-xs text-[var(--binary-text)] transition-colors hover:bg-[var(--binary-primary)]/10"
                        :class="
                            selectedHost === h.name
                                ? 'bg-[var(--binary-primary)]/10'
                                : ''
                        "
                        @click="onHostClick(h.name)"
                    >
                        <span
                            class="h-2 w-2 shrink-0 rounded-full border border-[var(--binary-primary)]"
                            :style="
                                geoSet.has(h.name)
                                    ? 'background: var(--binary-primary)'
                                    : ''
                            "
                        ></span>
                        <span class="truncate">{{ h.name }}</span>
                    </button>
                </div>
                <div
                    v-if="selectedHost && selectedEntityId !== null"
                    class="absolute top-6 right-6 z-10"
                >
                    <MemoryNodePanel
                        :entity-id="selectedEntityId"
                        :entity-name="selectedHost"
                        @close="selectedHost = null"
                        @changed="refreshGeo"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>

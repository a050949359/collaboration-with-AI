<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import AppLayout from '../layouts/AppLayout.vue';
import { api } from '../lib/routes';
import type { GraphData } from '../lib/topology';
import {
    TYPE_COLOR,
    drawGraph as drawGraphImpl,
    drawTopology as drawTopologyImpl,
} from '../lib/topology';

const tab = ref<'graph' | 'topology'>('graph');
const svgRef = ref<SVGSVGElement | null>(null);
const topoSvgRef = ref<SVGSVGElement | null>(null);
const loading = ref(true);
let graphData: GraphData = { entities: [], relations: [] };

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
    const res = await fetch(api.memoryGraph());
    graphData = await res.json();
    loading.value = false;
    await nextTick();

    if (tab.value === 'graph') {
        drawGraph(graphData);
    } else {
        drawTopology(graphData);
    }
}

watch(tab, async () => {
    simulation?.stop();
    await nextTick();

    if (tab.value === 'graph') {
        drawGraph(graphData);
    } else {
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
                            v-for="t in ['graph', 'topology'] as const"
                            :key="t"
                            class="rounded px-3 py-1 text-xs transition-colors"
                            :class="
                                tab === t
                                    ? 'bg-[var(--binary-primary)]/15 text-[var(--binary-primary)]'
                                    : 'text-[var(--binary-outline)] hover:text-[var(--binary-text)]'
                            "
                            @click="tab = t"
                        >
                            {{ t === 'graph' ? 'Graph' : 'Topology' }}
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
        </div>
    </AppLayout>
</template>

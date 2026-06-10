<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { api } from '../../lib/routes';
import type { GraphData } from '../../lib/topology';
import {
    drawGraph as drawGraphImpl,
    drawTopology as drawTopologyImpl,
} from '../../lib/topology';

const svgRef = ref<SVGSVGElement | null>(null);
const topoSvgRef = ref<SVGSVGElement | null>(null);
const loading = ref(true);
const empty = ref(false);
const tab = ref<'graph' | 'topology'>('graph');
let cachedData: GraphData = { entities: [], relations: [] };

let simulation: ReturnType<typeof drawGraphImpl> = null;

// ── Graph view ───────────────────────────────────────────────────

function drawGraph(data: GraphData) {
    simulation?.stop();

    if (!svgRef.value) {
        return;
    }

    simulation = drawGraphImpl(svgRef.value, data, {
        fallbackW: 500,
        fallbackH: 300,
        markerId: 'kgw-arrow',
        markerSize: 5,
        arrowFill: 'var(--binary-outline-variant)',
        linkStrokeWidth: 1,
        linkOpacity: 0.6,
        relFont: 8,
        relOpacity: 0.7,
        nodeRadiusBase: 8,
        nodeRadiusMul: 2,
        nodeFont: 9,
        nodeDy: 3,
        linkDistanceBase: 60,
        linkDistancePerRel: 0,
        chargeStrength: -300,
        forceStrength: 0.04,
        edgeGap: 4,
    });
    empty.value = simulation === null;
}

// ── Topology view ────────────────────────────────────────────────

function drawTopology(data: GraphData) {
    if (!topoSvgRef.value) {
        return;
    }

    drawTopologyImpl(topoSvgRef.value, data, {
        fallbackW: 500,
        fallbackH: 300,
        pad: 10,
        projH: 22,
        projW: 120,
        rowGap: 60,
        boxGap: 30,
        markerId: 'kgw-ta',
        hostRx: 8,
        projRx: 4,
        hubR: 22,
        hostFont: 9,
        projFont: 8,
        relFont: 7,
    });
}

// ── Data & lifecycle ─────────────────────────────────────────────

watch(tab, async () => {
    simulation?.stop();
    await nextTick();

    if (tab.value === 'graph') {
        drawGraph(cachedData);
    } else {
        drawTopology(cachedData);
    }
});

onMounted(async () => {
    try {
        const res = await fetch(api.memory.graph());
        cachedData = await res.json();
        loading.value = false;
        await nextTick();
        drawGraph(cachedData);
    } catch {
        loading.value = false;
        empty.value = true;
    }
});

onUnmounted(() => simulation?.stop());
</script>

<template>
    <div class="flex h-full w-full flex-col">
        <!-- Tab bar -->
        <div
            class="flex shrink-0 gap-1 border-b border-[var(--binary-outline-variant)] px-3 py-1.5"
        >
            <button
                v-for="t in ['graph', 'topology'] as const"
                :key="t"
                class="binary-label rounded px-2 py-0.5 text-[9px] uppercase transition-colors"
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

        <!-- Canvas -->
        <div class="relative min-h-0 flex-1">
            <div
                v-if="loading"
                class="absolute inset-0 flex items-center justify-center"
            >
                <span class="binary-cursor" />
            </div>
            <div
                v-else-if="empty"
                class="absolute inset-0 flex items-center justify-center text-xs text-[var(--binary-outline)]"
            >
                > NO_DATA
            </div>
            <template v-else>
                <svg
                    v-show="tab === 'graph'"
                    ref="svgRef"
                    class="h-full w-full"
                />
                <svg
                    v-show="tab === 'topology'"
                    ref="topoSvgRef"
                    class="h-full w-full"
                />
            </template>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    drag,
    forceCenter,
    forceLink,
    forceManyBody,
    forceSimulation,
    forceX,
    forceY,
    select,
    zoom,
    zoomIdentity,
} from 'd3';
import { onMounted, onUnmounted, ref } from 'vue';
import { nextTick, watch } from 'vue';
import AppLayout from '../layouts/AppLayout.vue';
import { api } from '../lib/routes';
import { drawTopology as drawTopologyImpl } from '../lib/topology';

interface Entity {
    id: number;
    name: string;
    type: string;
    observation_count: number;
}
interface Relation {
    from: string;
    relation_type: string;
    to: string;
}
interface GraphData {
    entities: Entity[];
    relations: Relation[];
}

interface GraphNode extends Entity {
    x?: number;
    y?: number;
    fx?: number | null;
    fy?: number | null;
}
interface GraphLink {
    source: GraphNode;
    target: GraphNode;
    relation_types: string[];
}

const tab = ref<'graph' | 'topology'>('graph');
const svgRef = ref<SVGSVGElement | null>(null);
const topoSvgRef = ref<SVGSVGElement | null>(null);
const loading = ref(true);
let graphData: GraphData = { entities: [], relations: [] };

const TYPE_COLOR: Record<string, string> = {
    project: 'var(--binary-primary)',
    host: '#a78bfa',
    service: '#22d3ee',
};

function typeColor(type: string) {
    return TYPE_COLOR[type] ?? 'var(--binary-outline)';
}

let simulation: ReturnType<typeof forceSimulation> | null = null;

// ── Graph view ───────────────────────────────────────────────────

function drawGraph(data: GraphData) {
    simulation?.stop();
    const nodes: GraphNode[] = data.entities
        .filter((e) => e.type !== 'host')
        .map((e) => ({ ...e }));
    const nodeMap = Object.fromEntries(nodes.map((n) => [n.name, n]));

    const linkMap = new Map<string, GraphLink>();
    data.relations
        .filter(
            (r) =>
                nodeMap[r.from] &&
                nodeMap[r.to] &&
                r.relation_type !== 'deployed_on',
        )
        .forEach((r) => {
            const key = `${r.from}→${r.to}`;

            if (linkMap.has(key)) {
                linkMap.get(key)!.relation_types.push(r.relation_type);
            } else {
                linkMap.set(key, {
                    source: nodeMap[r.from],
                    target: nodeMap[r.to],
                    relation_types: [r.relation_type],
                });
            }
        });
    const links: GraphLink[] = [...linkMap.values()];

    const svg = select(svgRef.value!);
    svg.selectAll('*').remove();
    const W = svgRef.value!.clientWidth || 800;
    const H = svgRef.value!.clientHeight || 560;
    const g = svg.append('g');

    const zoomBehavior = zoom<SVGSVGElement, unknown>()
        .scaleExtent([0.3, 3])
        .on('zoom', (e) => g.attr('transform', e.transform));
    svg.call(zoomBehavior).on('dblclick.zoom', null);

    svg.append('defs')
        .append('marker')
        .attr('id', 'arrow')
        .attr('viewBox', '0 -4 8 8')
        .attr('refX', 8)
        .attr('refY', 0)
        .attr('markerWidth', 6)
        .attr('markerHeight', 6)
        .attr('orient', 'auto')
        .append('path')
        .attr('d', 'M0,-4L8,0L0,4')
        .attr('fill', 'var(--binary-outline)');

    const linkG = g.append('g').selectAll('g').data(links).join('g');

    const linkLine = linkG
        .append('line')
        .attr('stroke', 'var(--binary-outline-variant)')
        .attr('stroke-width', 1.5)
        .attr('marker-end', 'url(#arrow)');

    linkG.each(function (d) {
        d.relation_types.forEach((rt, i) => {
            select(this)
                .append('text')
                .attr('class', `rl rl-${i}`)
                .text(rt)
                .attr('font-size', 9)
                .attr('fill', 'var(--binary-outline)')
                .attr('text-anchor', 'middle')
                .attr('dominant-baseline', 'middle');
        });
    });

    const nodeRadius = (d: GraphNode) => 12 + d.observation_count * 3;

    const nodeG = g
        .append('g')
        .selectAll('g')
        .data(nodes)
        .join('g')
        .style('cursor', 'grab');

    nodeG
        .append('circle')
        .attr('r', nodeRadius)
        .attr('fill', (d) => typeColor(d.type) + '22')
        .attr('stroke', (d) => typeColor(d.type))
        .attr('stroke-width', 1.5);

    nodeG
        .append('text')
        .text((d) => d.name)
        .attr('font-size', 11)
        .attr('fill', 'var(--binary-text)')
        .attr('text-anchor', 'middle')
        .attr('dy', 4);

    const dragBehavior = drag<SVGGElement, GraphNode>()
        .on('start', (e, d) => {
            if (!e.active) {
                simulation!.alphaTarget(0.3).restart();
            }

            d.fx = d.x;
            d.fy = d.y;
        })
        .on('drag', (e, d) => {
            d.fx = e.x;
            d.fy = e.y;
        })
        .on('end', (e, d) => {
            if (!e.active) {
                simulation!.alphaTarget(0);
            }

            d.fx = null;
            d.fy = null;
        });
    nodeG.call(dragBehavior);

    simulation = forceSimulation(nodes)
        .force(
            'link',
            forceLink(links)
                .distance(
                    (d: GraphLink) =>
                        nodeRadius(d.source as GraphNode) +
                        nodeRadius(d.target as GraphNode) +
                        80 +
                        d.relation_types.length * 40,
                )
                .strength(0.4),
        )
        .force('charge', forceManyBody().strength(-600))
        .force('center', forceCenter(W / 2, H / 2))
        .force('x', forceX(W / 2).strength(0.05))
        .force('y', forceY(H / 2).strength(0.05))
        .on('tick', () => {
            linkLine.each(function (d) {
                const s = d.source as GraphNode,
                    t = d.target as GraphNode;
                const dx = t.x! - s.x!,
                    dy = t.y! - s.y!;
                const dist = Math.sqrt(dx * dx + dy * dy) || 1;
                const r = nodeRadius(t) + 6;
                select(this)
                    .attr('x1', s.x!)
                    .attr('y1', s.y!)
                    .attr('x2', t.x! - (dx / dist) * r)
                    .attr('y2', t.y! - (dy / dist) * r);
            });
            linkG.each(function (d) {
                const s = d.source as GraphNode,
                    t = d.target as GraphNode;
                const n = d.relation_types.length;
                select(this)
                    .selectAll<SVGTextElement, string>('text.rl')
                    .each(function (_, i) {
                        const frac = (i + 1) / (n + 1);
                        select(this)
                            .attr('x', s.x! + (t.x! - s.x!) * frac)
                            .attr('y', s.y! + (t.y! - s.y!) * frac);
                    });
            });
            nodeG.attr('transform', (d) => `translate(${d.x},${d.y})`);
        });

    svg.call(zoomBehavior.transform, zoomIdentity);
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

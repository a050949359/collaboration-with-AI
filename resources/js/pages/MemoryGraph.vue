<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { drag, forceCenter, forceLink, forceManyBody, forceSimulation, select, zoom, zoomIdentity } from 'd3';
import AppLayout from '../layouts/AppLayout.vue';
import { api } from '../lib/routes';

interface Entity { id: number; name: string; type: string; observations: string[] }
interface Relation { from: string; relation_type: string; to: string }

interface GraphNode extends Entity { x?: number; y?: number; fx?: number | null; fy?: number | null }
interface GraphLink { source: GraphNode; target: GraphNode; relation_type: string }

const svgRef = ref<SVGSVGElement | null>(null);
const loading = ref(true);
const selected = ref<GraphNode | null>(null);

const TYPE_COLOR: Record<string, string> = {
    project: 'var(--binary-primary)',
    host:    '#a78bfa',
    service: '#34d399',
};

function typeColor(type: string) {
    return TYPE_COLOR[type] ?? 'var(--binary-outline)';
}

let simulation: ReturnType<typeof forceSimulation> | null = null;

async function fetchAndDraw() {
    loading.value = true;
    const res = await fetch(api.memoryGraph());
    const data: { entities: Entity[]; relations: Relation[] } = await res.json();
    loading.value = false;

    const nodes: GraphNode[] = data.entities.map(e => ({ ...e }));
    const nodeMap = Object.fromEntries(nodes.map(n => [n.name, n]));

    const links: GraphLink[] = data.relations
        .filter(r => nodeMap[r.from] && nodeMap[r.to])
        .map(r => ({ source: nodeMap[r.from], target: nodeMap[r.to], relation_type: r.relation_type }));

    const svg = select(svgRef.value!);
    svg.selectAll('*').remove();

    const W = svgRef.value!.clientWidth || 800;
    const H = svgRef.value!.clientHeight || 560;

    const g = svg.append('g');

    // Zoom
    const zoomBehavior = zoom<SVGSVGElement, unknown>()
        .scaleExtent([0.3, 3])
        .on('zoom', e => g.attr('transform', e.transform));
    svg.call(zoomBehavior).on('dblclick.zoom', null);

    // Arrow marker
    svg.append('defs').append('marker')
        .attr('id', 'arrow')
        .attr('viewBox', '0 -4 8 8')
        .attr('refX', 8).attr('refY', 0)
        .attr('markerWidth', 6).attr('markerHeight', 6)
        .attr('orient', 'auto')
        .append('path')
        .attr('d', 'M0,-4L8,0L0,4')
        .attr('fill', 'var(--binary-outline)');

    // Links
    const linkG = g.append('g').selectAll('g').data(links).join('g');

    const linkLine = linkG.append('line')
        .attr('stroke', 'var(--binary-outline-variant)')
        .attr('stroke-width', 1.5)
        .attr('marker-end', 'url(#arrow)');

    const linkLabel = linkG.append('text')
        .text(d => d.relation_type)
        .attr('font-size', 9)
        .attr('fill', 'var(--binary-outline)')
        .attr('text-anchor', 'middle')
        .attr('dy', -4);

    // Nodes
    const nodeG = g.append('g').selectAll('g').data(nodes).join('g')
        .style('cursor', 'pointer')
        .on('click', (_, d) => { selected.value = d; });

    const nodeRadius = (d: GraphNode) => 12 + d.observations.length * 3;

    nodeG.append('circle')
        .attr('r', nodeRadius)
        .attr('fill', d => typeColor(d.type) + '22')
        .attr('stroke', d => typeColor(d.type))
        .attr('stroke-width', 1.5);

    nodeG.append('text')
        .text(d => d.name)
        .attr('font-size', 11)
        .attr('fill', 'var(--binary-text)')
        .attr('text-anchor', 'middle')
        .attr('dy', 4);

    // Drag
    const dragBehavior = drag<SVGGElement, GraphNode>()
        .on('start', (e, d) => { if (!e.active) simulation!.alphaTarget(0.3).restart(); d.fx = d.x; d.fy = d.y; })
        .on('drag', (e, d) => { d.fx = e.x; d.fy = e.y; })
        .on('end', (e, d) => { if (!e.active) simulation!.alphaTarget(0); d.fx = null; d.fy = null; });
    nodeG.call(dragBehavior);

    // Simulation
    simulation = forceSimulation(nodes)
        .force('link', forceLink(links).distance((d: GraphLink) => nodeRadius(d.source as GraphNode) + nodeRadius(d.target as GraphNode) + 60).strength(0.8))
        .force('charge', forceManyBody().strength(-400))
        .force('center', forceCenter(W / 2, H / 2))
        .on('tick', () => {
            linkLine.each(function(d) {
                const s = d.source as GraphNode;
                const t = d.target as GraphNode;
                const dx = t.x! - s.x!, dy = t.y! - s.y!;
                const dist = Math.sqrt(dx * dx + dy * dy) || 1;
                const r = nodeRadius(t) + 6;
                select(this)
                    .attr('x1', s.x!).attr('y1', s.y!)
                    .attr('x2', t.x! - dx / dist * r)
                    .attr('y2', t.y! - dy / dist * r);
            });
            linkLabel
                .attr('x', d => ((d.source as GraphNode).x! + (d.target as GraphNode).x!) / 2)
                .attr('y', d => ((d.source as GraphNode).y! + (d.target as GraphNode).y!) / 2);
            nodeG.attr('transform', d => `translate(${d.x},${d.y})`);
        });

    // Reset zoom
    svg.call(zoomBehavior.transform, zoomIdentity.translate(0, 0).scale(1));
}

onMounted(fetchAndDraw);
onUnmounted(() => simulation?.stop());
</script>

<template>
    <Head title="Knowledge Graph" />
    <AppLayout>
        <div class="flex flex-col h-[calc(100vh-4rem)]">
            <!-- Header -->
            <div class="flex items-center justify-between px-6 py-3 border-b border-[var(--binary-outline-variant)] shrink-0">
                <h1 class="text-base font-bold text-[var(--binary-text)]">Knowledge Graph</h1>
                <div class="flex items-center gap-4">
                    <!-- Legend -->
                    <div class="flex items-center gap-3 text-xs text-[var(--binary-outline)]">
                        <span v-for="(color, type) in TYPE_COLOR" :key="type" class="flex items-center gap-1">
                            <span class="w-2.5 h-2.5 rounded-full border" :style="`background:${color}22;border-color:${color}`"></span>
                            {{ type }}
                        </span>
                    </div>
                    <button class="text-xs text-[var(--binary-outline)] hover:text-[var(--binary-text)] transition-colors" @click="fetchAndDraw">↺</button>
                </div>
            </div>

            <div class="flex flex-1 min-h-0">
                <!-- Graph -->
                <div class="flex-1 relative">
                    <div v-if="loading" class="absolute inset-0 flex items-center justify-center text-xs text-[var(--binary-outline)]">載入中…</div>
                    <svg ref="svgRef" class="w-full h-full" />
                </div>

                <!-- Side panel -->
                <div v-if="selected" class="w-64 border-l border-[var(--binary-outline-variant)] p-4 shrink-0 overflow-y-auto">
                    <div class="flex items-start justify-between mb-3">
                        <div>
                            <div class="font-bold text-sm text-[var(--binary-text)]">{{ selected.name }}</div>
                            <span class="text-xs font-mono px-1.5 py-0.5 rounded mt-1 inline-block"
                                :style="`background:${typeColor(selected.type)}22;color:${typeColor(selected.type)}`">
                                {{ selected.type }}
                            </span>
                        </div>
                        <button class="text-[var(--binary-outline)] hover:text-[var(--binary-text)] text-sm" @click="selected = null">✕</button>
                    </div>
                    <div class="text-xs text-[var(--binary-outline)] mb-1">{{ selected.observations.length }} 條觀察</div>
                    <ul class="space-y-1.5">
                        <li v-for="(obs, i) in selected.observations" :key="i"
                            class="text-xs text-[var(--binary-text-variant)] flex gap-1.5">
                            <span class="text-[var(--binary-outline)] shrink-0">·</span>
                            <span>{{ obs }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

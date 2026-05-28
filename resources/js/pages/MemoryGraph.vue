<script setup lang="ts">
import { onMounted, onUnmounted, ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { drag, forceCenter, forceLink, forceManyBody, forceSimulation, select, zoom, zoomIdentity } from 'd3';
import AppLayout from '../layouts/AppLayout.vue';
import { api } from '../lib/routes';

interface Entity { id: number; name: string; type: string; observations: string[] }
interface Relation { from: string; relation_type: string; to: string }
interface GraphData { entities: Entity[]; relations: Relation[] }

interface GraphNode extends Entity { x?: number; y?: number; fx?: number | null; fy?: number | null }
interface GraphLink { source: GraphNode; target: GraphNode; relation_types: string[] }

const tab = ref<'graph' | 'topology'>('graph');
const svgRef = ref<SVGSVGElement | null>(null);
const topoSvgRef = ref<SVGSVGElement | null>(null);
const loading = ref(true);
let graphData: GraphData = { entities: [], relations: [] };

const TYPE_COLOR: Record<string, string> = {
    project: 'var(--binary-primary)',
    host:    '#a78bfa',
    service: '#34d399',
};

function typeColor(type: string) {
    return TYPE_COLOR[type] ?? 'var(--binary-outline)';
}

let simulation: ReturnType<typeof forceSimulation> | null = null;

// ── Graph view ───────────────────────────────────────────────────

function drawGraph(data: GraphData) {
    simulation?.stop();
    const nodes: GraphNode[] = data.entities.map(e => ({ ...e }));
    const nodeMap = Object.fromEntries(nodes.map(n => [n.name, n]));

    const linkMap = new Map<string, GraphLink>();
    data.relations
        .filter(r => nodeMap[r.from] && nodeMap[r.to] && r.relation_type !== 'deployed_on')
        .forEach(r => {
            const key = `${r.from}→${r.to}`;
            if (linkMap.has(key)) {
                linkMap.get(key)!.relation_types.push(r.relation_type);
            } else {
                linkMap.set(key, { source: nodeMap[r.from], target: nodeMap[r.to], relation_types: [r.relation_type] });
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
        .on('zoom', e => g.attr('transform', e.transform));
    svg.call(zoomBehavior).on('dblclick.zoom', null);

    svg.append('defs').append('marker')
        .attr('id', 'arrow').attr('viewBox', '0 -4 8 8')
        .attr('refX', 8).attr('refY', 0)
        .attr('markerWidth', 6).attr('markerHeight', 6)
        .attr('orient', 'auto')
        .append('path').attr('d', 'M0,-4L8,0L0,4').attr('fill', 'var(--binary-outline)');

    const linkG = g.append('g').selectAll('g').data(links).join('g');

    const linkLine = linkG.append('line')
        .attr('stroke', 'var(--binary-outline-variant)')
        .attr('stroke-width', 1.5)
        .attr('marker-end', 'url(#arrow)');

    linkG.each(function(d) {
        d.relation_types.forEach((rt, i) => {
            select(this).append('text').attr('class', `rl rl-${i}`)
                .text(rt).attr('font-size', 9)
                .attr('fill', 'var(--binary-outline)')
                .attr('text-anchor', 'middle')
                .attr('dominant-baseline', 'middle');
        });
    });

    const nodeRadius = (d: GraphNode) => 12 + d.observations.length * 3;

    const nodeG = g.append('g').selectAll('g').data(nodes).join('g').style('cursor', 'grab');

    nodeG.append('circle')
        .attr('r', nodeRadius)
        .attr('fill', d => typeColor(d.type) + '22')
        .attr('stroke', d => typeColor(d.type))
        .attr('stroke-width', 1.5);

    nodeG.append('text')
        .text(d => d.name).attr('font-size', 11)
        .attr('fill', 'var(--binary-text)').attr('text-anchor', 'middle').attr('dy', 4);

    const dragBehavior = drag<SVGGElement, GraphNode>()
        .on('start', (e, d) => { if (!e.active) simulation!.alphaTarget(0.3).restart(); d.fx = d.x; d.fy = d.y; })
        .on('drag', (e, d) => { d.fx = e.x; d.fy = e.y; })
        .on('end', (e, d) => { if (!e.active) simulation!.alphaTarget(0); d.fx = null; d.fy = null; });
    nodeG.call(dragBehavior);

    simulation = forceSimulation(nodes)
        .force('link', forceLink(links).distance((d: GraphLink) => nodeRadius(d.source as GraphNode) + nodeRadius(d.target as GraphNode) + 80 + d.relation_types.length * 40).strength(0.4))
        .force('charge', forceManyBody().strength(-1200))
        .force('center', forceCenter(W / 2, H / 2))
        .on('tick', () => {
            linkLine.each(function(d) {
                const s = d.source as GraphNode, t = d.target as GraphNode;
                const dx = t.x! - s.x!, dy = t.y! - s.y!;
                const dist = Math.sqrt(dx * dx + dy * dy) || 1;
                const r = nodeRadius(t) + 6;
                select(this).attr('x1', s.x!).attr('y1', s.y!)
                    .attr('x2', t.x! - dx / dist * r).attr('y2', t.y! - dy / dist * r);
            });
            linkG.each(function(d) {
                const s = d.source as GraphNode, t = d.target as GraphNode;
                const n = d.relation_types.length;
                select(this).selectAll<SVGTextElement, string>('text.rl').each(function(_, i) {
                    const frac = (i + 1) / (n + 1);
                    select(this).attr('x', s.x! + (t.x! - s.x!) * frac).attr('y', s.y! + (t.y! - s.y!) * frac);
                });
            });
            nodeG.attr('transform', d => `translate(${d.x},${d.y})`);
        });

    svg.call(zoomBehavior.transform, zoomIdentity);
}

// ── Topology view ────────────────────────────────────────────────

function drawTopology(data: GraphData) {
    const svg = select(topoSvgRef.value!);
    svg.selectAll('*').remove();
    const W = topoSvgRef.value!.clientWidth || 800;
    const H = topoSvgRef.value!.clientHeight || 560;

    const hosts = data.entities.filter(e => e.type === 'host');
    const projects = data.entities.filter(e => e.type === 'project');
    const deployedOn = data.relations.filter(r => r.relation_type === 'deployed_on');
    const otherRels = data.relations.filter(r => r.relation_type !== 'deployed_on');

    // Group projects by host
    const hostProjects: Record<string, string[]> = {};
    hosts.forEach(h => { hostProjects[h.name] = []; });
    const unhosted: string[] = [];
    projects.forEach(p => {
        const rel = deployedOn.find(r => r.from === p.name);
        if (rel && hostProjects[rel.to] !== undefined) {
            hostProjects[rel.to].push(p.name);
        } else {
            unhosted.push(p.name);
        }
    });

    const PAD = 16;
    const PROJ_H = 32;
    const PROJ_W = 160;
    const BOX_W = PROJ_W + PAD * 2;

    // Build columns: hosts + unhosted group
    interface Column { label: string; isHost: boolean; items: string[] }
    const cols: Column[] = [
        ...hosts.map(h => ({ label: h.name, isHost: true, items: hostProjects[h.name] })),
        ...(unhosted.length ? [{ label: '未部署', isHost: false, items: unhosted }] : []),
    ];

    const colSpacing = Math.max(BOX_W + 80, W / (cols.length + 1));
    const startX = (W - colSpacing * (cols.length - 1) - BOX_W) / 2;

    // Project centre positions for drawing arrows
    const projPos: Record<string, { x: number; y: number }> = {};

    const g = svg.append('g');

    // Zoom
    const zoomBehavior = zoom<SVGSVGElement, unknown>().scaleExtent([0.3, 2]).on('zoom', e => g.attr('transform', e.transform));
    svg.call(zoomBehavior).on('dblclick.zoom', null);

    // Draw columns
    cols.forEach((col, ci) => {
        const boxH = PAD * 2 + col.items.length * (PROJ_H + PAD);
        const bx = startX + ci * colSpacing;
        const by = (H - boxH) / 2;

        // Host container
        g.append('rect')
            .attr('x', bx).attr('y', by).attr('width', BOX_W).attr('height', boxH)
            .attr('rx', 10)
            .attr('fill', col.isHost ? '#a78bfa11' : 'var(--binary-surface-container)')
            .attr('stroke', col.isHost ? '#a78bfa' : 'var(--binary-outline-variant)')
            .attr('stroke-width', 1.5);

        // Host label
        g.append('text')
            .text(col.label)
            .attr('x', bx + BOX_W / 2).attr('y', by - 8)
            .attr('text-anchor', 'middle').attr('font-size', 11)
            .attr('fill', col.isHost ? '#a78bfa' : 'var(--binary-outline)');

        // Project boxes
        col.items.forEach((name, pi) => {
            const px = bx + PAD;
            const py = by + PAD + pi * (PROJ_H + PAD);
            const cx = px + PROJ_W / 2;
            const cy = py + PROJ_H / 2;
            projPos[name] = { x: cx, y: cy };

            g.append('rect')
                .attr('x', px).attr('y', py).attr('width', PROJ_W).attr('height', PROJ_H)
                .attr('rx', 6)
                .attr('fill', 'var(--binary-primary)' + '18')
                .attr('stroke', 'var(--binary-primary)').attr('stroke-width', 1);

            g.append('text').text(name)
                .attr('x', cx).attr('y', cy)
                .attr('text-anchor', 'middle').attr('dominant-baseline', 'middle')
                .attr('font-size', 10).attr('fill', 'var(--binary-text)');
        });
    });

    // Arrow marker
    svg.append('defs').append('marker')
        .attr('id', 'topo-arrow').attr('viewBox', '0 -4 8 8')
        .attr('refX', 8).attr('refY', 0).attr('markerWidth', 5).attr('markerHeight', 5).attr('orient', 'auto')
        .append('path').attr('d', 'M0,-4L8,0L0,4').attr('fill', 'var(--binary-primary)');

    // Draw cross-project relations
    otherRels.forEach(r => {
        const s = projPos[r.from], t = projPos[r.to];
        if (!s || !t) return;
        const mx = (s.x + t.x) / 2, my = (s.y + t.y) / 2;
        g.append('path')
            .attr('d', `M${s.x},${s.y} Q${mx},${my - 40} ${t.x},${t.y}`)
            .attr('fill', 'none').attr('stroke', 'var(--binary-primary)').attr('stroke-width', 1)
            .attr('opacity', 0.5).attr('marker-end', 'url(#topo-arrow)');
        g.append('text').text(r.relation_type)
            .attr('x', mx).attr('y', my - 44)
            .attr('text-anchor', 'middle').attr('font-size', 8).attr('fill', 'var(--binary-outline)');
    });
}

// ── Data fetch ───────────────────────────────────────────────────

async function fetchAndRender() {
    loading.value = true;
    const res = await fetch(api.memoryGraph());
    graphData = await res.json();
    loading.value = false;
    await nextTick();
    if (tab.value === 'graph') drawGraph(graphData);
    else drawTopology(graphData);
}

import { nextTick, watch } from 'vue';

watch(tab, async () => {
    simulation?.stop();
    await nextTick();
    if (tab.value === 'graph') drawGraph(graphData);
    else drawTopology(graphData);
});

onMounted(fetchAndRender);
onUnmounted(() => simulation?.stop());
</script>

<template>
    <Head title="Knowledge Graph" />
    <AppLayout>
        <div class="flex flex-col h-screen pt-[72px]">
            <!-- Header -->
            <div class="flex items-center justify-between px-6 py-3 border-b border-[var(--binary-outline-variant)] shrink-0">
                <div class="flex items-center gap-4">
                    <h1 class="text-base font-bold text-[var(--binary-text)]">Knowledge Graph</h1>
                    <!-- Tabs -->
                    <div class="flex gap-1">
                        <button v-for="t in (['graph', 'topology'] as const)" :key="t"
                            class="text-xs px-3 py-1 rounded transition-colors"
                            :class="tab === t ? 'bg-[var(--binary-primary)]/15 text-[var(--binary-primary)]' : 'text-[var(--binary-outline)] hover:text-[var(--binary-text)]'"
                            @click="tab = t">
                            {{ t === 'graph' ? 'Graph' : 'Topology' }}
                        </button>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <div v-if="tab === 'graph'" class="flex items-center gap-3 text-xs text-[var(--binary-outline)]">
                        <span v-for="(color, type) in TYPE_COLOR" :key="type" class="flex items-center gap-1">
                            <span class="w-2.5 h-2.5 rounded-full border" :style="`background:${color}22;border-color:${color}`"></span>
                            {{ type }}
                        </span>
                    </div>
                    <button class="text-xs text-[var(--binary-outline)] hover:text-[var(--binary-text)] transition-colors" @click="fetchAndRender">↺</button>
                </div>
            </div>

            <!-- Graph -->
            <div v-show="tab === 'graph'" class="flex-1 relative min-h-0">
                <div v-if="loading" class="absolute inset-0 flex items-center justify-center text-xs text-[var(--binary-outline)]">載入中…</div>
                <svg ref="svgRef" class="w-full h-full" />
            </div>

            <!-- Topology -->
            <div v-show="tab === 'topology'" class="flex-1 relative min-h-0">
                <div v-if="loading" class="absolute inset-0 flex items-center justify-center text-xs text-[var(--binary-outline)]">載入中…</div>
                <svg ref="topoSvgRef" class="w-full h-full" />
            </div>
        </div>
    </AppLayout>
</template>

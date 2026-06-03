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
    const svg = select(topoSvgRef.value!);
    svg.selectAll('*').remove();
    const W = topoSvgRef.value!.clientWidth || 800;
    const H = topoSvgRef.value!.clientHeight || 560;

    const PAD = 14;
    const PROJ_H = 28;
    const PROJ_W = 150;
    const BOX_W = PROJ_W + PAD * 2;
    const ROW_GAP = 80;
    const HOST_COLOR = '#a78bfa';
    const PROJ_COLOR = 'var(--binary-primary)';

    const hosts = data.entities.filter((e) => e.type === 'host');
    const projects = data.entities.filter((e) => e.type === 'project');
    const hostNames = new Set(hosts.map((h) => h.name));
    const deployedOn = data.relations.filter(
        (r) => r.relation_type === 'deployed_on',
    );
    const hostHostRels = data.relations.filter(
        (r) => hostNames.has(r.from) && hostNames.has(r.to),
    );

    // Group projects per host
    const hostProjects: Record<string, string[]> = {};
    hosts.forEach((h) => {
        hostProjects[h.name] = [];
    });
    const unhosted: string[] = [];
    projects.forEach((p) => {
        const rels = deployedOn.filter((r) => r.from === p.name);

        if (rels.length) {
            rels.forEach((r) => {
                if (hostProjects[r.to]) {
                    hostProjects[r.to].push(p.name);
                }
            });
        } else {
            unhosted.push(p.name);
        }
    });

    // Connected components (undirected) → groups
    const adj: Record<string, Set<string>> = {};
    hosts.forEach((h) => {
        adj[h.name] = new Set();
    });
    hostHostRels.forEach((r) => {
        adj[r.from]?.add(r.to);
        adj[r.to]?.add(r.from);
    });
    const visited = new Set<string>();
    const components: string[][] = [];
    hosts.forEach((h) => {
        if (visited.has(h.name)) {
            return;
        }

        const comp: string[] = [];
        const q = [h.name];

        while (q.length) {
            const n = q.shift()!;

            if (visited.has(n)) {
                continue;
            }

            visited.add(n);
            comp.push(n);
            adj[n]?.forEach((nb) => {
                if (!visited.has(nb)) {
                    q.push(nb);
                }
            });
        }

        components.push(comp);
    });

    if (unhosted.length) {
        components.push(['__unhosted__']);
    }

    // BFS layering within each component
    const layerOf: Record<string, number> = {};
    components.forEach((comp) => {
        const compSet = new Set(comp);
        const inDeg: Record<string, number> = {};
        const children: Record<string, string[]> = {};
        comp.forEach((n) => {
            inDeg[n] = 0;
            children[n] = [];
        });
        hostHostRels
            .filter((r) => compSet.has(r.from) && compSet.has(r.to))
            .forEach((r) => {
                inDeg[r.to]++;
                children[r.from].push(r.to);
            });
        let q = comp.filter((n) => !inDeg[n]);
        q.forEach((n) => {
            layerOf[n] = 0;
        });

        while (q.length) {
            const next: string[] = [];
            q.forEach((n) =>
                children[n].forEach((c) => {
                    layerOf[c] = Math.max(
                        layerOf[c] ?? 0,
                        (layerOf[n] ?? 0) + 1,
                    );

                    if (!next.includes(c)) {
                        next.push(c);
                    }
                }),
            );
            q = next;
        }

        comp.forEach((n) => {
            if (layerOf[n] === undefined) {
                layerOf[n] = 0;
            }
        });
    });

    // Build rows per component: rows[compIdx][layer] = host[]
    const compRows: string[][][] = components.map((comp) => {
        if (comp[0] === '__unhosted__') {
            return [['__unhosted__']];
        }

        const maxL = Math.max(...comp.map((n) => layerOf[n] ?? 0), 0);
        const rows: string[][] = Array.from({ length: maxL + 1 }, () => []);
        comp.forEach((n) => rows[layerOf[n] ?? 0].push(n));

        return rows;
    });

    const boxH = (hostName: string) => {
        const items =
            hostName === '__unhosted__'
                ? unhosted
                : (hostProjects[hostName] ?? []);

        return PAD * 2 + Math.max(1, items.length) * (PROJ_H + PAD);
    };
    const rowH = (row: string[]) => Math.max(...row.map(boxH));
    const compW = (rows: string[][]) =>
        Math.max(...rows.map((r) => r.length)) * (BOX_W + 40) - 40;
    const compH = (rows: string[][]) =>
        rows.reduce((s, r) => s + rowH(r) + ROW_GAP, 0) - ROW_GAP;

    const hostBox: Record<
        string,
        { x: number; y: number; w: number; h: number }
    > = {};
    const projPos: Record<string, { x: number; y: number }> = {};

    const g = svg.append('g');
    const zoomBehavior = zoom<SVGSVGElement, unknown>()
        .scaleExtent([0.2, 2])
        .on('zoom', (e) => g.attr('transform', e.transform));
    svg.call(zoomBehavior).on('dblclick.zoom', null);

    svg.append('defs')
        .append('marker')
        .attr('id', 'ta')
        .attr('viewBox', '0 -4 8 8')
        .attr('refX', 8)
        .attr('refY', 0)
        .attr('markerWidth', 5)
        .attr('markerHeight', 5)
        .attr('orient', 'auto')
        .append('path')
        .attr('d', 'M0,-4L8,0L0,4')
        .attr('fill', HOST_COLOR);

    const COMP_GAP = 60;
    const totalCompW =
        compRows.reduce((s, rows) => s + compW(rows) + COMP_GAP, 0) - COMP_GAP;
    let compX = (W - totalCompW) / 2;

    compRows.forEach((rows) => {
        const cw = compW(rows);
        const ch = compH(rows);
        let startY = (H - ch) / 2;

        rows.forEach((row) => {
            const rh = rowH(row);
            const rowTotalW = row.length * BOX_W + (row.length - 1) * 40;
            let bx = compX + (cw - rowTotalW) / 2;

            row.forEach((hostName) => {
                const isUnhosted = hostName === '__unhosted__';
                const items = isUnhosted
                    ? unhosted
                    : (hostProjects[hostName] ?? []);
                const bh = boxH(hostName);
                const by = startY + (rh - bh) / 2;

                hostBox[hostName] = { x: bx, y: by, w: BOX_W, h: bh };

                g.append('rect')
                    .attr('x', bx)
                    .attr('y', by)
                    .attr('width', BOX_W)
                    .attr('height', bh)
                    .attr('rx', 10)
                    .attr(
                        'fill',
                        isUnhosted ? 'transparent' : HOST_COLOR + '11',
                    )
                    .attr(
                        'stroke',
                        isUnhosted
                            ? 'var(--binary-outline-variant)'
                            : HOST_COLOR,
                    )
                    .attr('stroke-width', 1.5)
                    .attr('stroke-dasharray', isUnhosted ? '4,3' : 'none');

                g.append('text')
                    .text(isUnhosted ? '未部署' : hostName)
                    .attr('x', bx + BOX_W / 2)
                    .attr('y', by - 6)
                    .attr('text-anchor', 'middle')
                    .attr('font-size', 10)
                    .attr(
                        'fill',
                        isUnhosted ? 'var(--binary-outline)' : HOST_COLOR,
                    );

                items.forEach((name, pi) => {
                    const px = bx + PAD,
                        py = by + PAD + pi * (PROJ_H + PAD);
                    const cx = px + PROJ_W / 2,
                        cy = py + PROJ_H / 2;
                    projPos[name] = { x: cx, y: cy };
                    g.append('rect')
                        .attr('x', px)
                        .attr('y', py)
                        .attr('width', PROJ_W)
                        .attr('height', PROJ_H)
                        .attr('rx', 5)
                        .attr('fill', PROJ_COLOR + '18')
                        .attr('stroke', PROJ_COLOR)
                        .attr('stroke-width', 1);
                    g.append('text')
                        .text(name)
                        .attr('x', cx)
                        .attr('y', cy)
                        .attr('text-anchor', 'middle')
                        .attr('dominant-baseline', 'middle')
                        .attr('font-size', 9)
                        .attr('fill', 'var(--binary-text)');
                });

                bx += BOX_W + 40;
            });

            startY += rh + ROW_GAP;
        });

        compX += cw + COMP_GAP;
    });

    // Host→Host arrows
    hostHostRels.forEach((r) => {
        const s = hostBox[r.from],
            t = hostBox[r.to];

        if (!s || !t) {
            return;
        }

        const sx = s.x + s.w / 2,
            sy = s.y + s.h;
        const tx = t.x + t.w / 2,
            ty = t.y;
        g.append('line')
            .attr('x1', sx)
            .attr('y1', sy)
            .attr('x2', tx)
            .attr('y2', ty - 6)
            .attr('stroke', HOST_COLOR)
            .attr('stroke-width', 1.2)
            .attr('opacity', 0.6)
            .attr('marker-end', 'url(#ta)');
        g.append('text')
            .text(r.relation_type)
            .attr('x', (sx + tx) / 2 + 4)
            .attr('y', (sy + ty) / 2)
            .attr('font-size', 8)
            .attr('fill', HOST_COLOR)
            .attr('opacity', 0.7);
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
        <div class="nav-pt-lg flex h-screen flex-col">
            <!-- Header -->
            <div
                class="flex shrink-0 items-center justify-between border-b border-[var(--binary-outline-variant)] px-6 py-3"
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
                        class="flex items-center gap-3 text-xs text-[var(--binary-outline)]"
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

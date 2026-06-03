<script setup lang="ts">
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
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { api } from '../../lib/routes';

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

const svgRef = ref<SVGSVGElement | null>(null);
const topoSvgRef = ref<SVGSVGElement | null>(null);
const loading = ref(true);
const empty = ref(false);
const tab = ref<'graph' | 'topology'>('graph');
let cachedData: GraphData = { entities: [], relations: [] };

const TYPE_COLOR: Record<string, string> = {
    project: 'var(--binary-primary)',
    host: '#a78bfa',
    service: '#22d3ee',
};
function typeColor(t: string) {
    return TYPE_COLOR[t] ?? 'var(--binary-outline)';
}

let simulation: ReturnType<typeof forceSimulation> | null = null;

// ── Graph view ───────────────────────────────────────────────────

function drawGraph(data: GraphData) {
    simulation?.stop();

    if (!svgRef.value) {
        return;
    }

    const nodes: GraphNode[] = data.entities
        .filter((e) => e.type !== 'host')
        .map((e) => ({ ...e }));

    if (!nodes.length) {
        empty.value = true;

        return;
    }

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

    const svg = select(svgRef.value);
    svg.selectAll('*').remove();
    const W = svgRef.value.clientWidth || 500;
    const H = svgRef.value.clientHeight || 300;
    const g = svg.append('g');

    const zoomBehavior = zoom<SVGSVGElement, unknown>()
        .scaleExtent([0.3, 3])
        .on('zoom', (e) => g.attr('transform', e.transform));
    svg.call(zoomBehavior).on('dblclick.zoom', null);

    svg.append('defs')
        .append('marker')
        .attr('id', 'kgw-arrow')
        .attr('viewBox', '0 -4 8 8')
        .attr('refX', 8)
        .attr('refY', 0)
        .attr('markerWidth', 5)
        .attr('markerHeight', 5)
        .attr('orient', 'auto')
        .append('path')
        .attr('d', 'M0,-4L8,0L0,4')
        .attr('fill', 'var(--binary-outline-variant)');

    const linkG = g.append('g').selectAll('g').data(links).join('g');
    const linkLine = linkG
        .append('line')
        .attr('stroke', 'var(--binary-outline-variant)')
        .attr('stroke-width', 1)
        .attr('opacity', 0.6)
        .attr('marker-end', 'url(#kgw-arrow)');

    linkG.each(function (d) {
        d.relation_types.forEach((rt, i) => {
            select(this)
                .append('text')
                .attr('class', `rl rl-${i}`)
                .text(rt)
                .attr('font-size', 8)
                .attr('fill', 'var(--binary-outline)')
                .attr('text-anchor', 'middle')
                .attr('dominant-baseline', 'middle')
                .attr('opacity', 0.7);
        });
    });

    const nodeRadius = (d: GraphNode) => 8 + d.observation_count * 2;
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
        .attr('font-size', 9)
        .attr('fill', 'var(--binary-text)')
        .attr('text-anchor', 'middle')
        .attr('dy', 3);

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
                        60,
                )
                .strength(0.4),
        )
        .force('charge', forceManyBody().strength(-300))
        .force('center', forceCenter(W / 2, H / 2))
        .force('x', forceX(W / 2).strength(0.04))
        .force('y', forceY(H / 2).strength(0.04))
        .on('tick', () => {
            linkLine.each(function (d) {
                const s = d.source as GraphNode,
                    t = d.target as GraphNode;
                const dx = t.x! - s.x!,
                    dy = t.y! - s.y!;
                const dist = Math.sqrt(dx * dx + dy * dy) || 1;
                const r = nodeRadius(t) + 4;
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

    const svg = select(topoSvgRef.value);
    svg.selectAll('*').remove();
    const W = topoSvgRef.value.clientWidth || 500;
    const H = topoSvgRef.value.clientHeight || 300;

    const PAD = 10,
        PROJ_H = 22,
        PROJ_W = 120,
        BOX_W = PROJ_W + PAD * 2,
        ROW_GAP = 60;
    const HOST_COLOR = '#a78bfa',
        PROJ_COLOR = 'var(--binary-primary)';

    const hosts = data.entities.filter((e) => e.type === 'host');
    const projects = data.entities.filter((e) => e.type === 'project');
    const hostNames = new Set(hosts.map((h) => h.name));
    const deployedOn = data.relations.filter(
        (r) => r.relation_type === 'deployed_on',
    );
    const hostHostRels = data.relations.filter(
        (r) => hostNames.has(r.from) && hostNames.has(r.to),
    );

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

        const comp: string[] = [],
            q = [h.name];

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

    const layerOf: Record<string, number> = {};
    components.forEach((comp) => {
        const compSet = new Set(comp);
        const inDeg: Record<string, number> = {},
            children: Record<string, string[]> = {};
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

    const compRows: string[][][] = components.map((comp) => {
        if (comp[0] === '__unhosted__') {
            return [['__unhosted__']];
        }

        const maxL = Math.max(...comp.map((n) => layerOf[n] ?? 0), 0);
        const rows: string[][] = Array.from({ length: maxL + 1 }, () => []);
        comp.forEach((n) => rows[layerOf[n] ?? 0].push(n));

        return rows;
    });

    const boxH = (hostName: string) =>
        PAD * 2 +
        Math.max(
            1,
            (hostName === '__unhosted__'
                ? unhosted
                : (hostProjects[hostName] ?? [])
            ).length,
        ) *
            (PROJ_H + PAD);
    const rowH = (row: string[]) => Math.max(...row.map(boxH));
    const compW = (rows: string[][]) =>
        Math.max(...rows.map((r) => r.length)) * (BOX_W + 30) - 30;
    const compH = (rows: string[][]) =>
        rows.reduce((s, r) => s + rowH(r) + ROW_GAP, 0) - ROW_GAP;

    const hostBox: Record<
        string,
        { x: number; y: number; w: number; h: number }
    > = {};
    const g = svg.append('g');
    const zoomBehavior = zoom<SVGSVGElement, unknown>()
        .scaleExtent([0.2, 2])
        .on('zoom', (e) => g.attr('transform', e.transform));
    svg.call(zoomBehavior).on('dblclick.zoom', null);

    svg.append('defs')
        .append('marker')
        .attr('id', 'kgw-ta')
        .attr('viewBox', '0 -4 8 8')
        .attr('refX', 8)
        .attr('refY', 0)
        .attr('markerWidth', 5)
        .attr('markerHeight', 5)
        .attr('orient', 'auto')
        .append('path')
        .attr('d', 'M0,-4L8,0L0,4')
        .attr('fill', HOST_COLOR);

    const COMP_GAP = 50;
    const totalCompW =
        compRows.reduce((s, rows) => s + compW(rows) + COMP_GAP, 0) - COMP_GAP;
    let compX = (W - totalCompW) / 2;

    compRows.forEach((rows) => {
        const cw = compW(rows),
            ch = compH(rows);
        let startY = (H - ch) / 2;
        rows.forEach((row) => {
            const rh = rowH(row),
                rowTotalW = row.length * BOX_W + (row.length - 1) * 30;
            let bx = compX + (cw - rowTotalW) / 2;
            row.forEach((hostName) => {
                const isUnhosted = hostName === '__unhosted__';
                const items = isUnhosted
                    ? unhosted
                    : (hostProjects[hostName] ?? []);
                const bh = boxH(hostName),
                    by = startY + (rh - bh) / 2;
                hostBox[hostName] = { x: bx, y: by, w: BOX_W, h: bh };

                g.append('rect')
                    .attr('x', bx)
                    .attr('y', by)
                    .attr('width', BOX_W)
                    .attr('height', bh)
                    .attr('rx', 8)
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
                    .attr('y', by - 5)
                    .attr('text-anchor', 'middle')
                    .attr('font-size', 9)
                    .attr(
                        'fill',
                        isUnhosted ? 'var(--binary-outline)' : HOST_COLOR,
                    );

                items.forEach((name, pi) => {
                    const px = bx + PAD,
                        py = by + PAD + pi * (PROJ_H + PAD);
                    g.append('rect')
                        .attr('x', px)
                        .attr('y', py)
                        .attr('width', PROJ_W)
                        .attr('height', PROJ_H)
                        .attr('rx', 4)
                        .attr('fill', PROJ_COLOR + '18')
                        .attr('stroke', PROJ_COLOR)
                        .attr('stroke-width', 1);
                    g.append('text')
                        .text(name)
                        .attr('x', px + PROJ_W / 2)
                        .attr('y', py + PROJ_H / 2)
                        .attr('text-anchor', 'middle')
                        .attr('dominant-baseline', 'middle')
                        .attr('font-size', 8)
                        .attr('fill', 'var(--binary-text)');
                });
                bx += BOX_W + 30;
            });
            startY += rh + ROW_GAP;
        });
        compX += cw + COMP_GAP;
    });

    hostHostRels.forEach((r) => {
        const s = hostBox[r.from],
            t = hostBox[r.to];

        if (!s || !t) {
            return;
        }

        g.append('line')
            .attr('x1', s.x + s.w / 2)
            .attr('y1', s.y + s.h)
            .attr('x2', t.x + t.w / 2)
            .attr('y2', t.y - 6)
            .attr('stroke', HOST_COLOR)
            .attr('stroke-width', 1.2)
            .attr('opacity', 0.6)
            .attr('marker-end', 'url(#kgw-ta)');
        g.append('text')
            .text(r.relation_type)
            .attr('x', (s.x + s.w / 2 + t.x + t.w / 2) / 2 + 4)
            .attr('y', (s.y + s.h + t.y) / 2)
            .attr('font-size', 7)
            .attr('fill', HOST_COLOR)
            .attr('opacity', 0.7);
    });

    svg.call(zoomBehavior.transform, zoomIdentity);
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
        const res = await fetch(api.memoryGraph());
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

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
        PROJ_COLOR = 'var(--binary-primary)',
        ZT_COLOR = '#ffb441';

    const hosts = data.entities.filter((e) => e.type === 'host');
    const projects = data.entities.filter((e) => e.type === 'project');
    const hostNames = new Set(hosts.map((h) => h.name));
    const deployedOn = data.relations.filter(
        (r) => r.relation_type === 'deployed_on',
    );
    const hostHostRels = data.relations.filter(
        (r) => hostNames.has(r.from) && hostNames.has(r.to),
    );
    // ZeroTier hub = zerotier 關係匯聚的 to 端，畫成特別色圓圈而非主機方框
    const ztHubNames = new Set(
        hostHostRels
            .filter((r) => r.relation_type === 'zerotier')
            .map((r) => r.to),
    );
    // ZeroTier 成員 = 連到 hub 的主機（zerotier 關係的 from 端）
    const ztMembers = new Set(
        hostHostRels
            .filter(
                (r) =>
                    r.relation_type === 'zerotier' && !ztHubNames.has(r.from),
            )
            .map((r) => r.from),
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

    // 固定三層：① ZeroTier hub ② ZeroTier 成員 ③ 其他（含未部署）
    const layerFor = (name: string) =>
        ztHubNames.has(name) ? 0 : ztMembers.has(name) ? 1 : 2;
    const layeredRows: string[][] = [[], [], []];
    hosts.forEach((h) => layeredRows[layerFor(h.name)].push(h.name));

    if (unhosted.length) {
        layeredRows[2].push('__unhosted__');
    }

    const rows = layeredRows.filter((r) => r.length);

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

    const totalH =
        rows.reduce((s, row) => s + rowH(row) + ROW_GAP, 0) - ROW_GAP;
    let startY = (H - totalH) / 2;

    rows.forEach((row) => {
        const rh = rowH(row),
            rowTotalW = row.length * BOX_W + (row.length - 1) * 30;
        let bx = (W - rowTotalW) / 2;
        row.forEach((hostName) => {
            const isUnhosted = hostName === '__unhosted__';
            const items = isUnhosted
                ? unhosted
                : (hostProjects[hostName] ?? []);
            const bh = boxH(hostName),
                by = startY + (rh - bh) / 2;
            hostBox[hostName] = { x: bx, y: by, w: BOX_W, h: bh };

            if (ztHubNames.has(hostName)) {
                // ZeroTier hub：特別色圓圈（中心對齊方框格，與虛線端點一致）
                const cx = bx + BOX_W / 2,
                    cy = by + bh / 2;
                g.append('circle')
                    .attr('cx', cx)
                    .attr('cy', cy)
                    .attr('r', 22)
                    .attr('fill', ZT_COLOR + '22')
                    .attr('stroke', ZT_COLOR)
                    .attr('stroke-width', 1.5);
                g.append('text')
                    .text(hostName)
                    .attr('x', cx)
                    .attr('y', by - 5)
                    .attr('text-anchor', 'middle')
                    .attr('font-size', 9)
                    .attr('fill', ZT_COLOR);
                bx += BOX_W + 30;

                return;
            }

            g.append('rect')
                .attr('x', bx)
                .attr('y', by)
                .attr('width', BOX_W)
                .attr('height', bh)
                .attr('rx', 8)
                .attr('fill', isUnhosted ? 'transparent' : HOST_COLOR + '11')
                .attr(
                    'stroke',
                    isUnhosted ? 'var(--binary-outline-variant)' : HOST_COLOR,
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

    hostHostRels.forEach((r) => {
        const s = hostBox[r.from],
            t = hostBox[r.to];

        if (!s || !t) {
            return;
        }

        const isZt = r.relation_type === 'zerotier';
        // ZeroTier：中心到中心、虛線、無箭頭（對等）；其餘：底→頂的階層箭頭
        const x1 = s.x + s.w / 2,
            y1 = isZt ? s.y + s.h / 2 : s.y + s.h;
        const x2 = t.x + t.w / 2,
            y2 = isZt ? t.y + t.h / 2 : t.y - 6;

        const line = g
            .append('line')
            .attr('x1', x1)
            .attr('y1', y1)
            .attr('x2', x2)
            .attr('y2', y2)
            .attr('stroke', isZt ? ZT_COLOR : HOST_COLOR)
            .attr('stroke-width', 1.2)
            .attr('opacity', 0.6);

        if (isZt) {
            line.attr('stroke-dasharray', '4,3');
        } else {
            line.attr('marker-end', 'url(#kgw-ta)');
        }

        g.append('text')
            .text(r.relation_type)
            .attr('x', (x1 + x2) / 2 + 4)
            .attr('y', (y1 + y2) / 2)
            .attr('font-size', 7)
            .attr('fill', isZt ? ZT_COLOR : HOST_COLOR)
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

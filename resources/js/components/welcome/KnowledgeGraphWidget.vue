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
import { nextTick, onMounted, onUnmounted, ref } from 'vue';
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
const loading = ref(true);
const empty = ref(false);

const TYPE_COLOR: Record<string, string> = {
    project: 'var(--binary-primary)',
    host: '#a78bfa',
    service: '#22d3ee',
};

function typeColor(type: string) {
    return TYPE_COLOR[type] ?? 'var(--binary-outline)';
}

let simulation: ReturnType<typeof forceSimulation> | null = null;

function draw(data: { entities: Entity[]; relations: Relation[] }) {
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

onMounted(async () => {
    try {
        const res = await fetch(api.memoryGraph());
        const data = await res.json();
        loading.value = false;
        await nextTick();
        draw(data);
    } catch {
        loading.value = false;
        empty.value = true;
    }
});

onUnmounted(() => simulation?.stop());
</script>

<template>
    <div class="relative h-full w-full">
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
        <svg v-else ref="svgRef" class="h-full w-full" />
    </div>
</template>

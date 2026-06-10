<script setup lang="ts">
// 知識圖譜 host 3D 地球（D3 orthographic）。沿用 AirportGlobe 的球體技法，
// 但資料來自 geo 觀察：每個 host 依經緯度落點，host 間的 relation 以大圓弧連線。
// 純渲染：points / relations 來自父層 props，編輯後重給即重畫；點 host pin → emit hostClick。
import {
    drag,
    geoDistance,
    geoGraticule,
    geoOrthographic,
    geoPath,
    json,
    select,
    zoom,
    zoomIdentity,
} from 'd3';
import type { GeoPath, GeoProjection, Selection } from 'd3';
import * as topojson from 'topojson-client';
import type { Topology } from 'topojson-specification';
import { onMounted, ref, watch } from 'vue';

interface GeoPoint {
    entity: string;
    content: string; // "lat,lng"
}
interface Relation {
    from: string;
    relation_type: string;
    to: string;
}

const props = defineProps<{
    points: GeoPoint[];
    relations: Relation[];
}>();

const emit = defineEmits<{ hostClick: [entity: string] }>();

const containerEl = ref<HTMLDivElement | null>(null);

const W = 780;
const H = 520;
const INITIAL_SCALE = 240;

let projection: GeoProjection;
let path: GeoPath;
let svgEl: Selection<SVGSVGElement, unknown, null, undefined>;
let gLand: Selection<SVGGElement, unknown, null, undefined>;
let gArcs: Selection<SVGGElement, unknown, null, undefined>;
let gPins: Selection<SVGGElement, unknown, null, undefined>;
let ready = false;
let renderRequested = false;

// entity → [lng, lat]
function coords(): Record<string, [number, number]> {
    const map: Record<string, [number, number]> = {};

    for (const p of props.points) {
        const [lat, lng] = p.content.split(',').map((s) => Number(s.trim()));

        if (Number.isFinite(lat) && Number.isFinite(lng)) {
            map[p.entity] = [lng, lat];
        }
    }

    return map;
}

function requestRender() {
    if (renderRequested) {
        return;
    }

    renderRequested = true;
    requestAnimationFrame(() => {
        renderRequested = false;
        render();
    });
}

function render() {
    if (!ready) {
        return;
    }

    svgEl.select<SVGPathElement>('.globe-sphere').attr('d', path as any);
    svgEl
        .select<SVGPathElement>('.graticule')
        .attr('d', path(geoGraticule()()) as any);
    gLand.selectAll<SVGPathElement, any>('path').attr('d', path as any);

    // arcs：大圓線，geoPath 會依 clipAngle 自動裁到可見半球
    gArcs
        .selectAll<SVGPathElement, [[number, number], [number, number]]>('path')
        .attr('d', (d) => path({ type: 'LineString', coordinates: d } as any));

    // pins：背面的點隱藏
    const center = projection.invert!([W / 2, H / 2])!;
    gPins
        .selectAll<SVGCircleElement, { c: [number, number] }>('circle')
        .attr('cx', (d) => projection(d.c)![0])
        .attr('cy', (d) => projection(d.c)![1])
        .attr('visibility', (d) =>
            geoDistance(d.c, center) > 1.57 ? 'hidden' : 'visible',
        );
}

function renderData() {
    if (!ready) {
        return;
    }

    const map = coords();

    // arcs：只連兩端都有 geo 的 relation
    const arcs = props.relations
        .filter((r) => map[r.from] && map[r.to])
        .map(
            (r) =>
                [map[r.from], map[r.to]] as [
                    [number, number],
                    [number, number],
                ],
        );

    gArcs.selectAll('path').remove();
    gArcs
        .selectAll('path')
        .data(arcs)
        .enter()
        .append('path')
        .attr('fill', 'none')
        .attr('stroke', '#6bdc9f')
        .attr('stroke-width', 1)
        .attr('stroke-opacity', 0.5);

    // pins
    gPins.selectAll('circle').remove();
    gPins
        .selectAll('circle')
        .data(Object.entries(map).map(([entity, c]) => ({ entity, c })))
        .enter()
        .append('circle')
        .attr('r', 5)
        .attr('fill', '#6bdc9f')
        .attr('stroke', '#07160e')
        .attr('stroke-width', 1)
        .style('cursor', 'pointer')
        .on('click', (_, d) => emit('hostClick', d.entity))
        .append('title')
        .text((d) => d.entity);

    requestRender();
}

async function initGlobe() {
    if (!containerEl.value) {
        return;
    }

    svgEl = select(containerEl.value)
        .append('svg')
        .attr('viewBox', `0 0 ${W} ${H}`)
        .attr('class', 'h-full w-full cursor-grab active:cursor-grabbing');

    projection = geoOrthographic()
        .scale(INITIAL_SCALE)
        .translate([W / 2, H / 2])
        .rotate([-121, -24]) // 初始轉到台灣附近（多數 host 在此）
        .clipAngle(90);

    path = geoPath().projection(projection);

    svgEl
        .append('path')
        .datum({ type: 'Sphere' } as any)
        .attr('class', 'globe-sphere')
        .attr('fill', '#0d141d')
        .attr('stroke', '#2ca46d')
        .attr('stroke-width', 0.4);

    svgEl
        .append('path')
        .datum(geoGraticule()())
        .attr('class', 'graticule')
        .attr('fill', 'none')
        .attr('stroke', 'rgba(107,220,159,0.1)')
        .attr('stroke-width', 0.5);

    gLand = svgEl.append('g');
    gArcs = svgEl.append('g');
    gPins = svgEl.append('g');

    const dragBehavior = drag<SVGSVGElement, unknown>().on('drag', (event) => {
        const r = projection.rotate();
        const k = 75 / projection.scale();
        projection.rotate([r[0] + event.dx * k, r[1] - event.dy * k]);
        requestRender();
    });

    const zoomBehavior = zoom<SVGSVGElement, unknown>()
        .scaleExtent([180, 1200])
        .on('zoom', (event) => {
            projection.scale(event.transform.k);
            requestRender();
        });

    svgEl.call(dragBehavior).call(zoomBehavior);
    svgEl.call(zoomBehavior.transform, zoomIdentity.scale(INITIAL_SCALE));

    const world = await json<Topology>(
        'https://cdn.jsdelivr.net/npm/world-atlas@2/countries-110m.json',
    );

    if (!world) {
        return;
    }

    const countries = (
        topojson.feature(world, (world.objects as any).countries) as any
    ).features;

    gLand
        .selectAll<SVGPathElement, any>('path')
        .data(countries)
        .enter()
        .append('path')
        .attr('fill', 'rgba(44,164,109,0.18)')
        .attr('stroke', '#2ca46d')
        .attr('stroke-width', 0.4);

    ready = true;
    renderData();
}

onMounted(initGlobe);

// props 變動（如編輯後重抓 geo）→ 重畫資料層
watch(
    () => [props.points, props.relations],
    () => renderData(),
    { deep: true },
);
</script>

<template>
    <div
        class="relative h-full w-full overflow-hidden rounded-xl border border-[var(--binary-outline-variant)] bg-[#0d141d]"
    >
        <div ref="containerEl" class="h-full w-full" />
    </div>
</template>

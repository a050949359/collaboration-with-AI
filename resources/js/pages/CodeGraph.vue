<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    forceCenter,
    forceCollide,
    forceLink,
    forceManyBody,
    forceSimulation,
    drag as d3drag,
    select,
    zoom,
} from 'd3';
import type { Simulation } from 'd3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import AppLayout from '../layouts/AppLayout.vue';
import { api } from '../lib/routes';

interface CNode {
    id: string;
    type: string;
    name: string;
    file: string;
    line: number;
    lang: string;
    x?: number;
    y?: number;
    fx?: number | null;
    fy?: number | null;
}
interface CEdge {
    source: string;
    target: string;
    type: string;
    confidence: number;
}

// 語言配色（D3 視覺化特定色，CLAUDE.md 允許的例外）
const LANG_COLOR: Record<string, string> = {
    go: '#6bdc9f',
    php: '#a78bfa',
    ts: '#22d3ee',
    other: '#9ca3af',
};
// route 節點與 HANDLES 邊的特別色（route→handler）；HTTP_CALLS（前端→route）另一色
const ROUTE_COLOR = '#f0a020';
const HTTP_COLOR = '#58a6ff';

const svgRef = ref<SVGSVGElement | null>(null);
const graph3dRef = ref<HTMLDivElement | null>(null);
const loading = ref(true);
const indexed = ref(true);
const mode3d = ref(false);
const allNodes = ref<CNode[]>([]);
const allEdges = ref<CEdge[]>([]);
const stats = ref<Record<string, number>>({});
const selected = ref<CNode | null>(null);

const langOn = ref<Record<string, boolean>>({
    go: true,
    php: true,
    ts: true,
    other: false,
});
const query = ref('');

let simulation: Simulation<CNode, undefined> | null = null;
// 3D：動態載入 3d-force-graph（含 Three.js），只有切到 3D 才載，2D 使用者零負擔

let ForceGraph3D: any = null;

let fg3d: any = null;

const nodeById = computed(() => {
    const m = new Map<string, CNode>();

    for (const n of allNodes.value) {
        m.set(n.id, n);
    }

    return m;
});

// 選取節點的 callers / callees（從完整邊集算，不受過濾影響）。
// 去重：同一目標被呼叫多次只算一個（避免重複、且 v-for key 唯一，防殘影）。
const callers = computed(() =>
    selected.value
        ? [
              ...new Set(
                  allEdges.value
                      .filter((e) => e.target === selected.value!.id)
                      .map((e) => e.source),
              ),
          ]
        : [],
);
const callees = computed(() =>
    selected.value
        ? [
              ...new Set(
                  allEdges.value
                      .filter((e) => e.source === selected.value!.id)
                      .map((e) => e.target),
              ),
          ]
        : [],
);

function shortName(id: string): string {
    return nodeById.value.get(id)?.name ?? id;
}

async function fetchGraph() {
    loading.value = true;

    try {
        const res = await fetch(api.codegraph.graph());
        const data = await res.json();
        indexed.value = data.indexed;
        allNodes.value = data.nodes ?? [];
        allEdges.value = data.edges ?? [];
        stats.value = data.stats?.lang ?? {};
    } finally {
        loading.value = false;
    }

    renderActive();
}

function filteredGraph(): { nodes: CNode[]; edges: CEdge[] } {
    const q = query.value.trim().toLowerCase();
    const nodes = allNodes.value.filter(
        (n) =>
            langOn.value[n.lang] &&
            (!q ||
                n.id.toLowerCase().includes(q) ||
                n.file.toLowerCase().includes(q)),
    );
    const ids = new Set(nodes.map((n) => n.id));
    const edges = allEdges.value.filter(
        (e) => ids.has(e.source) && ids.has(e.target),
    );

    return { nodes, edges };
}

const shownCount = ref(0);

function render() {
    const el = svgRef.value;

    if (!el) {
        return;
    }

    if (simulation) {
        simulation.stop();
    }

    const { nodes, edges } = filteredGraph();
    shownCount.value = nodes.length;

    // d3 會改寫節點物件，複製一份避免污染 ref
    const N: CNode[] = nodes.map((n) => ({ ...n }));
    const byId = new Map(N.map((n) => [n.id, n]));
    const L = edges
        .filter((e) => byId.has(e.source) && byId.has(e.target))
        .map((e) => ({ source: e.source, target: e.target, type: e.type }));

    const deg = new Map<string, number>();

    for (const e of L) {
        deg.set(e.source, (deg.get(e.source) ?? 0) + 1);
        deg.set(e.target, (deg.get(e.target) ?? 0) + 1);
    }

    const svg = select(el);
    svg.selectAll('*').remove();
    const width = el.clientWidth || 800;
    const height = el.clientHeight || 600;

    const g = svg.append('g');
    svg.call(
        zoom<SVGSVGElement, unknown>()
            .scaleExtent([0.1, 4])
            .on('zoom', (ev) => g.attr('transform', ev.transform)),
    );

    const link = g
        .append('g')
        .attr('stroke-opacity', 0.6)
        .selectAll('line')
        .data(L)
        .join('line')
        .attr('stroke', (d) =>
            d.type === 'HANDLES'
                ? ROUTE_COLOR
                : d.type === 'HTTP_CALLS'
                  ? HTTP_COLOR
                  : 'var(--binary-outline-variant)',
        )
        .attr('stroke-width', (d) => (d.type === 'CALLS' ? 1 : 1.5))
        .attr('stroke-dasharray', (d) => (d.type === 'CALLS' ? null : '4,3'));

    const node = g
        .append('g')
        .selectAll<SVGCircleElement, CNode>('circle')
        .data(N)
        .join('circle')
        .attr('r', (d) => 3 + Math.min(9, deg.get(d.id) ?? 0))
        .attr('fill', (d) =>
            d.type === 'route'
                ? ROUTE_COLOR
                : (LANG_COLOR[d.lang] ?? LANG_COLOR.other),
        )
        .attr('stroke', 'var(--binary-background)')
        .attr('stroke-width', 1)
        .style('cursor', 'pointer')
        .on('click', (_ev, d) => {
            selected.value = allNodes.value.find((n) => n.id === d.id) ?? null;
        });

    node.append('title').text((d) => `${d.id}\n${d.file}:${d.line}`);

    node.call(
        d3drag<SVGCircleElement, CNode>()
            .on('start', (ev, d) => {
                if (!ev.active) {
                    simulation?.alphaTarget(0.3).restart();
                }

                d.fx = d.x;
                d.fy = d.y;
            })
            .on('drag', (ev, d) => {
                d.fx = ev.x;
                d.fy = ev.y;
            })
            .on('end', (ev, d) => {
                if (!ev.active) {
                    simulation?.alphaTarget(0);
                }

                d.fx = null;
                d.fy = null;
            }),
    );

    simulation = forceSimulation<CNode>(N)
        .force(
            'link',
            forceLink<CNode, { source: string; target: string }>(L)
                .id((d) => d.id)
                .distance(45)
                .strength(0.35),
        )
        .force('charge', forceManyBody().strength(-70))
        .force('center', forceCenter(width / 2, height / 2))
        .force('collide', forceCollide(11))
        .on('tick', () => {
            link.attr('x1', (d) => (d.source as unknown as CNode).x!)
                .attr('y1', (d) => (d.source as unknown as CNode).y!)
                .attr('x2', (d) => (d.target as unknown as CNode).x!)
                .attr('y2', (d) => (d.target as unknown as CNode).y!);
            node.attr('cx', (d) => d.x!).attr('cy', (d) => d.y!);
        });
}

// 3D：用 3d-force-graph（Three.js）渲染同一份資料，node/edge 配色與 2D 一致。
async function render3d() {
    const el = graph3dRef.value;

    if (!el) {
        return;
    }

    if (!ForceGraph3D) {
        ForceGraph3D = (await import('3d-force-graph')).default;
    }

    const { nodes, edges } = filteredGraph();
    const N = nodes.map((n) => ({ ...n }));
    const byId = new Set(N.map((n) => n.id));
    const L = edges
        .filter((e) => byId.has(e.source) && byId.has(e.target))
        .map((e) => ({ source: e.source, target: e.target, type: e.type }));

    if (!fg3d) {
        fg3d = ForceGraph3D()(el)
            .backgroundColor('rgba(0,0,0,0)')
            .nodeLabel((n: CNode) => `${n.id}\n${n.file}:${n.line}`)
            .nodeColor((n: CNode) =>
                n.type === 'route'
                    ? ROUTE_COLOR
                    : (LANG_COLOR[n.lang] ?? LANG_COLOR.other),
            )
            .linkColor((l: { type: string }) =>
                l.type === 'HANDLES'
                    ? ROUTE_COLOR
                    : l.type === 'HTTP_CALLS'
                      ? HTTP_COLOR
                      : '#5b6270',
            )
            .linkOpacity(0.5)
            .linkDirectionalArrowLength(2.5)
            .linkDirectionalArrowRelPos(1)
            .onNodeClick((n: CNode) => {
                selected.value =
                    allNodes.value.find((x) => x.id === n.id) ?? null;
            });
    }

    fg3d.width(el.clientWidth).height(el.clientHeight);
    fg3d.graphData({ nodes: N, links: L });
}

function renderActive() {
    if (mode3d.value) {
        render3d();
    } else {
        render();
    }
}

let rerenderTimer: ReturnType<typeof setTimeout> | null = null;
watch(
    [langOn, query, mode3d],
    () => {
        if (rerenderTimer) {
            clearTimeout(rerenderTimer);
        }

        rerenderTimer = setTimeout(renderActive, 250);
    },
    { deep: true },
);

function onResize() {
    renderActive();
}

onMounted(() => {
    fetchGraph();
    window.addEventListener('resize', onResize);
});
onUnmounted(() => {
    simulation?.stop();

    if (fg3d?._destructor) {
        fg3d._destructor();
    }

    window.removeEventListener('resize', onResize);
});
</script>

<template>
    <Head title="CodeGraph" />
    <AppLayout>
        <div class="flex h-[calc(100vh-4rem)] flex-col">
            <!-- 工具列 -->
            <div
                class="flex flex-wrap items-center gap-3 border-b border-[var(--binary-outline-variant)] px-4 py-3"
            >
                <h1 class="text-sm font-semibold text-[var(--binary-text)]">
                    CodeGraph
                    <span class="text-[var(--binary-outline)]"
                        >程式碼結構圖</span
                    >
                </h1>

                <label
                    v-for="lang in ['go', 'php', 'ts']"
                    :key="lang"
                    class="flex items-center gap-1.5 text-xs text-[var(--binary-text-muted)]"
                >
                    <input
                        v-model="langOn[lang]"
                        type="checkbox"
                        class="accent-[var(--binary-primary)]"
                    />
                    <span
                        class="h-2.5 w-2.5 rounded-full"
                        :style="`background:${LANG_COLOR[lang]}`"
                    ></span>
                    {{ lang }}
                    <span class="text-[var(--binary-outline)]"
                        >({{ stats[lang] ?? 0 }})</span
                    >
                </label>

                <input
                    v-model="query"
                    type="text"
                    placeholder="過濾 名稱 / 檔案路徑…"
                    class="ml-auto w-56 rounded-lg border border-[var(--binary-outline-variant)] bg-[var(--binary-surface)] px-3 py-1.5 text-xs text-[var(--binary-text)] placeholder:text-[var(--binary-outline)] focus:border-[var(--binary-primary)] focus:outline-none"
                />
                <span class="text-xs text-[var(--binary-outline)]"
                    >顯示 {{ shownCount }} 節點</span
                >
                <button
                    class="rounded-lg border border-[var(--binary-outline-variant)] px-2.5 py-1 text-xs text-[var(--binary-text-muted)] transition-colors hover:text-[var(--binary-text)]"
                    :title="mode3d ? '切回 2D' : '切到 3D'"
                    @click="mode3d = !mode3d"
                >
                    {{ mode3d ? '3D' : '2D' }}
                </button>
                <button
                    class="text-xs text-[var(--binary-outline)] transition-colors hover:text-[var(--binary-text)]"
                    @click="fetchGraph"
                >
                    ↺
                </button>
            </div>

            <!-- 圖 -->
            <div class="relative min-h-0 flex-1">
                <div
                    v-if="loading"
                    class="absolute inset-0 flex items-center justify-center text-xs text-[var(--binary-outline)]"
                >
                    載入中…
                </div>
                <div
                    v-else-if="!indexed"
                    class="absolute inset-0 flex flex-col items-center justify-center gap-2 text-center text-xs text-[var(--binary-outline)]"
                >
                    <p>尚未建立索引。</p>
                    <code
                        class="rounded bg-[var(--binary-surface-high)] px-2 py-1 text-[var(--binary-text-muted)]"
                        >go run ./cmd/codegraph index .</code
                    >
                </div>
                <svg v-show="!mode3d" ref="svgRef" class="h-full w-full" />
                <div
                    v-show="mode3d"
                    ref="graph3dRef"
                    class="h-full w-full"
                ></div>

                <!-- 節點詳情 -->
                <div
                    v-if="selected"
                    class="binary-glass absolute top-4 right-4 z-10 max-h-[85%] w-72 overflow-y-auto rounded-xl border border-[var(--binary-outline-variant)] p-3 text-xs"
                >
                    <div class="mb-2 flex items-start justify-between gap-2">
                        <span
                            class="h-2.5 w-2.5 shrink-0 translate-y-1 rounded-full"
                            :style="`background:${LANG_COLOR[selected.lang]}`"
                        ></span>
                        <span
                            class="flex-1 font-semibold break-all text-[var(--binary-text)]"
                            >{{ selected.name }}</span
                        >
                        <button
                            class="text-[var(--binary-outline)] hover:text-[var(--binary-text)]"
                            @click="selected = null"
                        >
                            ✕
                        </button>
                    </div>
                    <p class="mb-1 break-all text-[var(--binary-text-muted)]">
                        {{ selected.id }}
                    </p>
                    <p class="mb-3 text-[var(--binary-outline)]">
                        {{ selected.type }} · {{ selected.file }}:{{
                            selected.line
                        }}
                    </p>

                    <p class="binary-label mb-1 text-[10px] uppercase">
                        呼叫它的（callers · {{ callers.length }}）
                    </p>
                    <ul class="mb-3 space-y-0.5">
                        <li
                            v-for="c in callers.slice(0, 30)"
                            :key="'i' + c"
                            class="cursor-pointer truncate text-[var(--binary-text-muted)] hover:text-[var(--binary-primary)]"
                            :title="c"
                            @click="
                                selected =
                                    allNodes.find((n) => n.id === c) ?? selected
                            "
                        >
                            {{ shortName(c) }}
                        </li>
                        <li
                            v-if="!callers.length"
                            class="text-[var(--binary-outline)]"
                        >
                            —
                        </li>
                    </ul>

                    <p class="binary-label mb-1 text-[10px] uppercase">
                        它呼叫的（callees · {{ callees.length }}）
                    </p>
                    <ul class="space-y-0.5">
                        <li
                            v-for="c in callees.slice(0, 30)"
                            :key="'o' + c"
                            class="cursor-pointer truncate text-[var(--binary-text-muted)] hover:text-[var(--binary-primary)]"
                            :title="c"
                            @click="
                                selected =
                                    allNodes.find((n) => n.id === c) ?? selected
                            "
                        >
                            {{ shortName(c) }}
                        </li>
                        <li
                            v-if="!callees.length"
                            class="text-[var(--binary-outline)]"
                        >
                            —
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

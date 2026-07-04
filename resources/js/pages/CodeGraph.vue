<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { forceCollide } from 'd3';
import ForceGraph from 'force-graph';
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
    z?: number;
}
interface CEdge {
    source: string;
    target: string;
    type: string;
    confidence: number;
}

// 語言配色（Canvas/WebGL 視覺化特定色，CLAUDE.md 允許的例外）
const LANG_COLOR: Record<string, string> = {
    go: '#6bdc9f',
    php: '#a78bfa',
    ts: '#22d3ee',
    other: '#9ca3af',
};
// route 節點與 HANDLES 邊的特別色（route→handler）；HTTP_CALLS（前端→route）另一色
const ROUTE_COLOR = '#f0a020';
const HTTP_COLOR = '#58a6ff';

const graph2dRef = ref<HTMLDivElement | null>(null);
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

// Canvas/WebGL 吃不了 CSS 變數，主題色取計算值後存這裡；
// 2D 每幀重繪都讀這份，換主題時（MutationObserver）即時生效
const theme = {
    primary: '#6bdc9f',
    background: '#0b100d',
    textMuted: '#a5d1b4',
    outlineVariant: 'rgba(165, 209, 180, 0.15)',
};

function refreshThemeColors() {
    const cs = getComputedStyle(document.documentElement);
    const get = (name: string, fallback: string) =>
        cs.getPropertyValue(name).trim() || fallback;
    theme.primary = get('--binary-primary', theme.primary);
    theme.background = get('--binary-background', theme.background);
    theme.textMuted = get('--binary-text-muted', theme.textMuted);
    theme.outlineVariant = get(
        '--binary-outline-variant',
        theme.outlineVariant,
    );
}

// 支援 #rrggbb 與 rgb()/rgba()（rgba 的話與原 alpha 相乘）
function withAlpha(color: string, alpha: number): string {
    if (color.startsWith('#') && color.length === 7) {
        return (
            color +
            Math.round(alpha * 255)
                .toString(16)
                .padStart(2, '0')
        );
    }

    const m = color.match(/rgba?\(([^)]+)\)/);

    if (m) {
        const parts = m[1].split(',').map((s) => s.trim());
        const base = parts.length === 4 ? parseFloat(parts[3]) : 1;

        return `rgba(${parts[0]}, ${parts[1]}, ${parts[2]}, ${base * alpha})`;
    }

    return color;
}

function esc(s: string): string {
    return s.replace(
        /[&<>]/g,
        (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[c]!,
    );
}

// graphData 餵入後 link 的 source/target 會被換成節點物件
function endId(v: string | CNode): string {
    return typeof v === 'string' ? v : v.id;
}

// 2D：force-graph（canvas 渲染，節點多也順）。
// 實例的 accessor 建立時綁定，每幀重繪會重新求值，
// 所以 hover / 選取 / 度數這些狀態放 module scope、直接改就會反映到畫面。
let fg2d: any = null;
let deg2d = new Map<string, number>();
let adj2d = new Map<string, Set<string>>();
let hover2d: string | null = null;
let labelCutoff = 3;
let didFit = false;

// 3D：動態載入 3d-force-graph（含 Three.js），只有切到 3D 才載，2D 使用者零負擔

let ForceGraph3D: any = null;

let fg3d: any = null;
let deg3d = new Map<string, number>();

let themeObserver: MutationObserver | null = null;

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

function nodeColor(n: CNode): string {
    return n.type === 'route'
        ? ROUTE_COLOR
        : (LANG_COLOR[n.lang] ?? LANG_COLOR.other);
}

async function fetchGraph() {
    loading.value = true;

    try {
        const res = await fetch(api.codegraph.graph());

        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }

        const data = await res.json();
        indexed.value = data.indexed;
        allNodes.value = data.nodes ?? [];
        allEdges.value = data.edges ?? [];
        stats.value = data.stats?.lang ?? {};
    } catch (e) {
        // 網路斷線 / 500 / 非 JSON：降級為空圖，不讓 loading 卡住或 console 爆未捕捉錯誤
        console.error('CodeGraph 載入失敗：', e);
        indexed.value = false;
        allNodes.value = [];
        allEdges.value = [];
    } finally {
        loading.value = false;
    }

    didFit = false; // 新資料佈局完成後 zoomToFit 一次
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

function buildDegAdj(L: { source: string; target: string }[]): {
    deg: Map<string, number>;
    adj: Map<string, Set<string>>;
} {
    const deg = new Map<string, number>();
    const adj = new Map<string, Set<string>>();

    for (const e of L) {
        deg.set(e.source, (deg.get(e.source) ?? 0) + 1);
        deg.set(e.target, (deg.get(e.target) ?? 0) + 1);

        if (!adj.has(e.source)) {
            adj.set(e.source, new Set());
        }

        if (!adj.has(e.target)) {
            adj.set(e.target, new Set());
        }

        adj.get(e.source)!.add(e.target);
        adj.get(e.target)!.add(e.source);
    }

    return { deg, adj };
}

function radius2d(id: string): number {
    return 3 + Math.min(9, deg2d.get(id) ?? 0);
}

function render2d() {
    const el = graph2dRef.value;

    if (!el) {
        return;
    }

    const { nodes, edges } = filteredGraph();
    shownCount.value = nodes.length;

    // 沿用上一輪的座標與速度，過濾條件改變時圖不會整個重新炸開
    const prev = new Map<string, CNode & { vx?: number; vy?: number }>();

    if (fg2d) {
        for (const n of fg2d.graphData().nodes) {
            prev.set(n.id, n);
        }
    }

    const N = nodes.map((n) => {
        const p = prev.get(n.id);

        return p ? { ...n, x: p.x, y: p.y, vx: p.vx, vy: p.vy } : { ...n };
    });
    const byId = new Set(N.map((n) => n.id));
    const L = edges
        .filter((e) => byId.has(e.source) && byId.has(e.target))
        .map((e) => ({ source: e.source, target: e.target, type: e.type }));

    ({ deg: deg2d, adj: adj2d } = buildDegAdj(L));

    // 高度數節點（前 ~30 名，至少度數 3）常駐顯示名稱標籤
    const degVals = N.map((n) => deg2d.get(n.id) ?? 0).sort((a, b) => b - a);
    labelCutoff = Math.max(3, degVals[Math.min(29, degVals.length - 1)] ?? 3);

    refreshThemeColors();

    if (!fg2d) {
        fg2d = new ForceGraph(el) as any;
        fg2d.backgroundColor('rgba(0,0,0,0)')
            .nodeLabel(
                (n: CNode) =>
                    `<div style="font-size:11px;line-height:1.4"><b>${esc(n.name)}</b><br/><span style="opacity:.7">${esc(n.file)}:${n.line}</span></div>`,
            )
            .nodeCanvasObject(
                (
                    n: CNode & { x: number; y: number },
                    ctx: CanvasRenderingContext2D,
                    scale: number,
                ) => {
                    const r = radius2d(n.id);
                    const lit =
                        !hover2d ||
                        n.id === hover2d ||
                        (adj2d.get(hover2d)?.has(n.id) ?? false);
                    ctx.globalAlpha = lit ? 1 : 0.12;
                    ctx.beginPath();
                    ctx.arc(n.x, n.y, r, 0, 2 * Math.PI);
                    ctx.fillStyle = nodeColor(n);
                    ctx.fill();

                    // 選取光圈（螢幕上恆定 2px 寬）
                    if (n.id === selected.value?.id) {
                        ctx.strokeStyle = theme.primary;
                        ctx.lineWidth = 2 / scale;
                        ctx.beginPath();
                        ctx.arc(n.x, n.y, r + 3 / scale, 0, 2 * Math.PI);
                        ctx.stroke();
                    }

                    // hub 常駐標籤；hover 時被聚焦的節點（自己+鄰居）也顯示
                    const hoverLit = hover2d !== null && lit;
                    const showLabel =
                        hoverLit ||
                        ((deg2d.get(n.id) ?? 0) >= labelCutoff && scale > 0.4);

                    if (showLabel) {
                        const size = 11 / scale;
                        ctx.font = `${size}px sans-serif`;
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'bottom';
                        const y = n.y - r - 3 / scale;
                        ctx.lineWidth = 3 / scale;
                        ctx.strokeStyle = theme.background;
                        ctx.strokeText(n.name, n.x, y);
                        ctx.fillStyle = theme.textMuted;
                        ctx.fillText(n.name, n.x, y);
                    }

                    ctx.globalAlpha = 1;
                },
            )
            .nodePointerAreaPaint(
                (
                    n: CNode & { x: number; y: number },
                    color: string,
                    ctx: CanvasRenderingContext2D,
                ) => {
                    ctx.fillStyle = color;
                    ctx.beginPath();
                    ctx.arc(n.x, n.y, radius2d(n.id) + 2, 0, 2 * Math.PI);
                    ctx.fill();
                },
            )
            .linkColor(
                (l: {
                    source: string | CNode;
                    target: string | CNode;
                    type: string;
                }) => {
                    const base =
                        l.type === 'HANDLES'
                            ? ROUTE_COLOR
                            : l.type === 'HTTP_CALLS'
                              ? HTTP_COLOR
                              : theme.outlineVariant;

                    if (!hover2d) {
                        return base;
                    }

                    return endId(l.source) === hover2d ||
                        endId(l.target) === hover2d
                        ? base
                        : withAlpha(base, 0.06);
                },
            )
            .linkWidth((l: { type: string }) => (l.type === 'CALLS' ? 1 : 1.5))
            .linkLineDash((l: { type: string }) =>
                l.type === 'CALLS' ? null : [4, 3],
            )
            .linkDirectionalParticles((l: { type: string }) =>
                l.type === 'CALLS' ? 0 : 2,
            )
            .linkDirectionalParticleWidth(2)
            .linkDirectionalParticleSpeed(0.006)
            .onNodeHover((n: CNode | null) => {
                hover2d = n?.id ?? null;
                el.style.cursor = n ? 'pointer' : '';
            })
            .onNodeClick((n: CNode) => {
                selected.value =
                    allNodes.value.find((x) => x.id === n.id) ?? null;
            })
            .onBackgroundClick(() => {
                selected.value = null;
            })
            .onEngineStop(() => {
                if (!didFit) {
                    didFit = true;
                    fg2d.zoomToFit(400, 60);
                }
            });
        fg2d.d3Force('charge')?.strength(-70);
        fg2d.d3Force('link')?.distance(45);
        fg2d.d3Force('collide', forceCollide(11));
    }

    fg2d.width(el.clientWidth).height(el.clientHeight);
    fg2d.graphData({ nodes: N, links: L });
    fg2d.resumeAnimation(); // 從 3D 切回時恢復 render loop（新建實例時為 no-op）
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
    shownCount.value = nodes.length;
    const N = nodes.map((n) => ({ ...n }));
    const byId = new Set(N.map((n) => n.id));
    const L = edges
        .filter((e) => byId.has(e.source) && byId.has(e.target))
        .map((e) => ({ source: e.source, target: e.target, type: e.type }));

    ({ deg: deg3d } = buildDegAdj(L));
    refreshThemeColors();

    if (!fg3d) {
        fg3d = new ForceGraph3D(el);
        fg3d.backgroundColor('rgba(0,0,0,0)')
            .nodeLabel(
                (n: CNode) =>
                    `<div style="font-size:11px;line-height:1.4"><b>${esc(n.name)}</b><br/><span style="opacity:.7">${esc(n.file)}:${n.line}</span></div>`,
            )
            .nodeResolution(12)
            .nodeOpacity(0.9)
            .nodeVal((n: CNode) => 1 + Math.min(10, deg3d.get(n.id) ?? 0))
            .nodeColor((n: CNode) =>
                n.id === selected.value?.id ? theme.primary : nodeColor(n),
            )
            .linkColor((l: { type: string }) =>
                l.type === 'HANDLES'
                    ? ROUTE_COLOR
                    : l.type === 'HTTP_CALLS'
                      ? HTTP_COLOR
                      : '#5b6270',
            )
            .linkOpacity(0.45)
            .linkWidth((l: { type: string }) => (l.type === 'CALLS' ? 0 : 1))
            .linkDirectionalArrowLength(2.5)
            .linkDirectionalArrowRelPos(1)
            .linkDirectionalParticles((l: { type: string }) =>
                l.type === 'CALLS' ? 0 : 2,
            )
            .linkDirectionalParticleWidth(1.6)
            .linkDirectionalParticleSpeed(0.006)
            .onNodeClick((n: CNode) => {
                selected.value =
                    allNodes.value.find((x) => x.id === n.id) ?? null;

                // 鏡頭平滑飛向點選的節點
                if (n.x != null && n.y != null && n.z != null) {
                    const dist = Math.hypot(n.x, n.y, n.z) || 1;
                    const ratio = 1 + 90 / dist;
                    fg3d.cameraPosition(
                        { x: n.x * ratio, y: n.y * ratio, z: n.z * ratio },
                        { x: n.x, y: n.y, z: n.z },
                        800,
                    );
                }
            })
            .onBackgroundClick(() => {
                selected.value = null;
            });
    }

    fg3d.width(el.clientWidth).height(el.clientHeight);
    fg3d.graphData({ nodes: N, links: L });
    fg3d.resumeAnimation(); // 從 2D 切回時恢復 render loop（新建實例時為 no-op）
}

function renderActive() {
    // 兩個實例都常駐（切換即恢復），只讓當前模式跑 render loop，省 GPU/CPU
    if (mode3d.value) {
        fg2d?.pauseAnimation();
        render3d();
    } else {
        fg3d?.pauseAnimation();
        render2d();
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

// 2D 是每幀重繪、選取光圈自動更新；3D 的 material 需要重新觸發 nodeColor
watch(selected, () => {
    if (fg3d && mode3d.value) {
        fg3d.nodeColor(fg3d.nodeColor());
    }
});

function onResize() {
    renderActive();
}

onMounted(() => {
    fetchGraph();
    window.addEventListener('resize', onResize);
    // 換主題（data-theme 變更）時更新 canvas 用色
    themeObserver = new MutationObserver(refreshThemeColors);
    themeObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-theme'],
    });
});
onUnmounted(() => {
    // 清掉 debounce timer：250ms 內離頁時避免它在已卸載的 refs 上觸發 renderActive
    if (rerenderTimer) {
        clearTimeout(rerenderTimer);
    }

    fg2d?._destructor?.();
    fg3d?._destructor?.();

    // module 變數跨頁面存活，不歸零的話重進頁面會拿到已銷毀的實例
    fg2d = null;
    fg3d = null;

    themeObserver?.disconnect();
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
                <h1 class="text-sm font-semibold">
                    <span class="text-gradient-primary">CodeGraph</span>
                    <span class="ml-1 text-[var(--binary-outline)]"
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
                <div
                    class="flex overflow-hidden rounded-lg border border-[var(--binary-outline-variant)] text-xs"
                >
                    <button
                        class="px-2.5 py-1 transition-colors"
                        :class="
                            !mode3d
                                ? 'bg-[var(--binary-surface-high)] font-semibold text-[var(--binary-primary)]'
                                : 'text-[var(--binary-text-muted)] hover:text-[var(--binary-text)]'
                        "
                        @click="mode3d = false"
                    >
                        2D
                    </button>
                    <button
                        class="px-2.5 py-1 transition-colors"
                        :class="
                            mode3d
                                ? 'bg-[var(--binary-surface-high)] font-semibold text-[var(--binary-primary)]'
                                : 'text-[var(--binary-text-muted)] hover:text-[var(--binary-text)]'
                        "
                        @click="mode3d = true"
                    >
                        3D
                    </button>
                </div>
                <button
                    class="text-xs text-[var(--binary-outline)] transition-all duration-300 hover:rotate-180 hover:text-[var(--binary-text)]"
                    title="重新載入"
                    @click="fetchGraph"
                >
                    ↺
                </button>
            </div>

            <!-- 圖 -->
            <div class="relative min-h-0 flex-1">
                <!-- 中央微光暈，給圖一點景深 -->
                <div
                    class="pointer-events-none absolute inset-0"
                    style="
                        background: radial-gradient(
                            ellipse at center,
                            color-mix(
                                in srgb,
                                var(--binary-primary) 6%,
                                transparent
                            ),
                            transparent 70%
                        );
                    "
                ></div>
                <div
                    v-if="loading"
                    class="absolute inset-0 flex flex-col items-center justify-center gap-3"
                >
                    <div
                        class="h-7 w-7 animate-spin rounded-full border-2 border-[var(--binary-outline-variant)] border-t-[var(--binary-primary)]"
                    ></div>
                    <span class="text-xs text-[var(--binary-outline)]"
                        >載入中…</span
                    >
                </div>
                <div
                    v-else-if="!indexed"
                    class="absolute inset-0 flex flex-col items-center justify-center gap-2 text-center text-xs text-[var(--binary-outline)]"
                >
                    <p>尚未建立索引：需要 codegraph.db</p>
                    <code
                        class="rounded bg-[var(--binary-surface-high)] px-2 py-1 text-[var(--binary-text-muted)]"
                        >database/codegraph.db</code
                    >
                    <p class="text-[var(--binary-outline)]">
                        在開發機執行 codegraph index 產生後放到此路徑
                    </p>
                </div>
                <div
                    v-show="!mode3d"
                    ref="graph2dRef"
                    class="h-full w-full"
                ></div>
                <div
                    v-show="mode3d"
                    ref="graph3dRef"
                    class="h-full w-full"
                ></div>

                <!-- 邊類型圖例 -->
                <div
                    v-if="!loading && indexed"
                    class="binary-glass absolute bottom-4 left-4 z-10 space-y-1.5 rounded-xl border border-[var(--binary-outline-variant)] px-3 py-2 text-[10px] text-[var(--binary-text-muted)]"
                >
                    <div class="flex items-center gap-2">
                        <svg width="22" height="6" class="shrink-0">
                            <line
                                x1="1"
                                y1="3"
                                x2="21"
                                y2="3"
                                stroke="var(--binary-outline)"
                                stroke-width="1.5"
                            />
                        </svg>
                        內部呼叫
                    </div>
                    <div class="flex items-center gap-2">
                        <svg width="22" height="6" class="shrink-0">
                            <line
                                x1="1"
                                y1="3"
                                x2="21"
                                y2="3"
                                :stroke="ROUTE_COLOR"
                                stroke-width="1.5"
                                stroke-dasharray="4,3"
                            />
                        </svg>
                        route → handler
                    </div>
                    <div class="flex items-center gap-2">
                        <svg width="22" height="6" class="shrink-0">
                            <line
                                x1="1"
                                y1="3"
                                x2="21"
                                y2="3"
                                :stroke="HTTP_COLOR"
                                stroke-width="1.5"
                                stroke-dasharray="4,3"
                            />
                        </svg>
                        前端 → API
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="flex w-[22px] shrink-0 justify-center">
                            <span
                                class="h-2 w-2 rounded-full"
                                :style="`background:${ROUTE_COLOR}`"
                            ></span>
                        </span>
                        route 節點
                    </div>
                </div>

                <!-- 節點詳情 -->
                <div
                    v-if="selected"
                    class="binary-glass absolute top-4 right-4 z-10 max-h-[85%] w-72 overflow-y-auto rounded-xl border border-[var(--binary-outline-variant)] p-3 text-xs"
                >
                    <div class="mb-2 flex items-start justify-between gap-2">
                        <span
                            class="h-2.5 w-2.5 shrink-0 translate-y-1 rounded-full"
                            :style="`background:${nodeColor(selected)}`"
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
                    <p class="mb-3 flex flex-wrap items-center gap-1.5">
                        <span
                            class="rounded bg-[var(--binary-surface-container)] px-1.5 py-0.5 text-[10px] text-[var(--binary-text-muted)]"
                            >{{ selected.type }}</span
                        >
                        <span class="break-all text-[var(--binary-outline)]"
                            >{{ selected.file }}:{{ selected.line }}</span
                        >
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

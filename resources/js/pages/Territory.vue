<script setup lang="ts">
// Territory 行政區瀏覽（範例頁）。
// 版面概念：地球取代背景動畫鋪滿整個視窗，未選國家時置中、選了之後往右位移，
// 左側滑入資料面板。位移用 globe.gl 的 globeOffset（canvas 尺寸全程不變），
// 不是改容器寬度——改寬度每幀都要 resize WebGL renderer，會頓。
import { Head } from '@inertiajs/vue3';
import * as topojson from 'topojson-client';
import type { Topology } from 'topojson-specification';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import AppLayout from '../layouts/AppLayout.vue';
import { api } from '../lib/routes';

interface Country {
    qid: string;
    label: string;
    label_en: string | null;
    iso_numeric: string | null;
    iso_code: string | null;
    continent: string | null;
    population: number | null;
    child_count: number;
}

interface Node {
    qid: string;
    type: string;
    label: string;
    description: string | null;
    lat: number | null;
    lng: number | null;
    population: number | null;
    area: number | null;
    has_gap: boolean;
}

const containerEl = ref<HTMLDivElement | null>(null);

let Globe: any = null;
let globeInstance: any = null;
let offsetRaf = 0;

const countries = ref<Country[]>([]);
const selected = ref<Country | null>(null);
const children = ref<Node[]>([]);
const isLoading = ref(false);
const loadError = ref('');
/** 國家清單載入失敗（跟「某國子節點載入失敗」分開，這個會讓整顆地球點不動） */
const countriesError = ref('');

/** iso 數字碼 → 國家。world-atlas 的 polygon id 就是這個碼，點擊時用來對照 QID。 */
const byIsoNumeric = computed(() => {
    const map = new Map<string, Country>();

    for (const c of countries.value) {
        if (c.iso_numeric) {
            map.set(c.iso_numeric, c);
        }
    }

    return map;
});

const worldStats = computed(() => {
    const withData = countries.value.filter((c) => c.child_count > 0);
    const totalChildren = countries.value.reduce(
        (sum, c) => sum + c.child_count,
        0,
    );

    return {
        countries: countries.value.length,
        withData: withData.length,
        totalChildren,
    };
});

/** 子節點依人口排序後取前幾名，畫成長條。人口缺值的節點排在後面。 */
const topChildren = computed(() => children.value.slice(0, 12));
const maxPopulation = computed(() =>
    Math.max(1, ...topChildren.value.map((c) => c.population ?? 0)),
);
const gapCount = computed(() => children.value.filter((c) => c.has_gap).length);

function formatNumber(n: number | null): string {
    return n == null ? '—' : n.toLocaleString();
}

/** 讀主題色給大氣層光暈用（沿用 CodeGraph 的做法：借 canvas 正規化任意 CSS 色）。 */
function themeColor(varName: string, fallback: string): string {
    const raw = getComputedStyle(document.documentElement)
        .getPropertyValue(varName)
        .trim();

    if (!raw) {
        return fallback;
    }

    const ctx = document.createElement('canvas').getContext('2d');

    if (!ctx) {
        return raw;
    }

    ctx.fillStyle = raw;

    return ctx.fillStyle;
}

/** globeOffset 沒有內建轉場，自己補一段 ease-out tween，避免位移用跳的。 */
function tweenOffset(toX: number, duration = 600) {
    if (!globeInstance) {
        return;
    }

    cancelAnimationFrame(offsetRaf);

    const fromX = globeInstance.globeOffset()[0] ?? 0;
    const start = performance.now();

    const step = (now: number) => {
        const t = Math.min(1, (now - start) / duration);
        const eased = 1 - Math.pow(1 - t, 3);

        globeInstance.globeOffset([fromX + (toX - fromX) * eased, 0]);

        if (t < 1) {
            offsetRaf = requestAnimationFrame(step);
        }
    };

    offsetRaf = requestAnimationFrame(step);
}

/** 選中時地球往右讓出左側面板空間；手機版空間不夠，改成不位移（面板走底部）。 */
function offsetForSelection(): number {
    if (window.innerWidth < 768) {
        return 0;
    }

    return window.innerWidth * 0.2;
}

async function selectCountry(country: Country) {
    selected.value = country;
    children.value = [];
    loadError.value = '';
    isLoading.value = true;

    // 停自動旋轉：已經聚焦在特定目標，繼續轉會慢慢轉離焦點
    if (globeInstance) {
        globeInstance.controls().autoRotate = false;
        globeInstance
            .polygonCapColor(globeInstance.polygonCapColor())
            .polygonStrokeColor(globeInstance.polygonStrokeColor());
    }

    tweenOffset(offsetForSelection());

    try {
        const res = await fetch(api.territory.children(country.qid), {
            headers: { Accept: 'application/json' },
        });
        const json = await res.json();

        if (!res.ok) {
            throw new Error(json?.message || 'Failed to load subdivisions');
        }

        // 同樣防呆：非陣列不要塞進 state，否則 topChildren 的 .slice() 會 render 失敗
        children.value = Array.isArray(json.children)
            ? (json.children as Node[])
            : [];

        // 鏡頭飛到該國第一個有座標的子節點附近，沒有就不動
        const anchor = children.value.find((c) => c.lat != null);

        if (anchor && globeInstance) {
            globeInstance.pointOfView(
                { lat: anchor.lat, lng: anchor.lng, altitude: 1.6 },
                800,
            );
        }
    } catch (error) {
        loadError.value =
            error instanceof Error ? error.message : 'Failed to load';
    } finally {
        isLoading.value = false;
    }
}

function backToWorld() {
    selected.value = null;
    children.value = [];
    loadError.value = '';
    tweenOffset(0);

    if (globeInstance) {
        globeInstance.controls().autoRotate = true;
        globeInstance
            .polygonCapColor(globeInstance.polygonCapColor())
            .polygonStrokeColor(globeInstance.polygonStrokeColor());
        globeInstance.pointOfView({ altitude: 2.4 }, 800);
    }
}

function resizeToContainer() {
    if (!containerEl.value || !globeInstance) {
        return;
    }

    globeInstance
        .width(containerEl.value.clientWidth)
        .height(containerEl.value.clientHeight);

    if (selected.value) {
        globeInstance.globeOffset([offsetForSelection(), 0]);
    }
}

async function initGlobe() {
    if (!containerEl.value) {
        return;
    }

    if (!Globe) {
        Globe = (await import('globe.gl')).default;
    }

    // await 期間元件可能已被卸載（見 AirportGlobe 的同一個防護）
    if (!containerEl.value) {
        return;
    }

    const primary = themeColor('--binary-primary', '#6bdc9f');

    globeInstance = new Globe(containerEl.value)
        .backgroundImageUrl('/images/globe/night-sky.png')
        // 夜間版：這顆球背後要壓文字跟卡片，白天版太亮會跟內容搶對比
        .globeImageUrl('/images/globe/earth-night.jpg')
        .bumpImageUrl('/images/globe/earth-topology.png')
        .showAtmosphere(true)
        .atmosphereColor(primary)
        .atmosphereAltitude(0.16)
        .polygonAltitude(0.006)
        .polygonCapColor((feat: any) =>
            isSelectedPolygon(feat)
                ? 'rgba(255,255,255,0.28)'
                : 'rgba(0,0,0,0)',
        )
        .polygonSideColor(() => 'rgba(0,0,0,0)')
        .polygonStrokeColor((feat: any) =>
            isSelectedPolygon(feat) ? '#ffffff' : primary,
        )
        .polygonLabel((feat: any) => {
            const c = byIsoNumeric.value.get(polygonIso(feat));

            return c ? `${c.label}（${c.child_count} 個一級行政區）` : '';
        })
        .onPolygonClick((feat: any) => {
            const c = byIsoNumeric.value.get(polygonIso(feat));

            // 有 9 個 country 節點沒有 ISO 數字碼（解體歷史實體/爭議地區），對不上 polygon
            if (c) {
                void selectCountry(c);
            }
        })
        .pointOfView({ lat: 20, lng: 0, altitude: 2.4 }, 0);

    globeInstance.controls().autoRotate = true;
    globeInstance.controls().autoRotateSpeed = 0.25;

    resizeToContainer();

    const world = await fetch(
        'https://cdn.jsdelivr.net/npm/world-atlas@2/countries-110m.json',
    ).then((r) => r.json() as Promise<Topology>);

    globeInstance.polygonsData(
        (topojson.feature(world, (world.objects as any).countries) as any)
            .features,
    );
}

function polygonIso(feat: any): string {
    return String(feat?.id ?? '').padStart(3, '0');
}

function isSelectedPolygon(feat: any): boolean {
    return !!selected.value && selected.value.iso_numeric === polygonIso(feat);
}

async function loadCountries() {
    countriesError.value = '';

    try {
        const res = await fetch(api.territory.countries(), {
            headers: { Accept: 'application/json' },
        });

        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }

        const json = await res.json();

        // 一定要確認是陣列再塞進 state：後端 500 時（例如 Redis 連不上）Laravel 會回
        // JSON 錯誤「物件」，fetch 不 throw、res.json() 也會成功，物件流進 computed 後
        // .filter()/.reduce() 會整頁 render 失敗，不只是資料空白而已。
        if (!Array.isArray(json)) {
            throw new Error('Unexpected response shape');
        }

        countries.value = json as Country[];
    } catch (error) {
        countriesError.value =
            error instanceof Error ? error.message : 'Failed to load';
    }
}

onMounted(async () => {
    await loadCountries();
    await initGlobe();
    window.addEventListener('resize', resizeToContainer);
});

onUnmounted(() => {
    cancelAnimationFrame(offsetRaf);
    window.removeEventListener('resize', resizeToContainer);
    globeInstance?._destructor?.();
    globeInstance = null;
});
</script>

<template>
    <Head title="Territory" />
    <AppLayout disable-background>
        <!-- 地球鋪滿視窗當背景層（取代主題背景動畫），內容疊在上面。
             z-index 不能用負值：.binary-page 是 relative、pointer-events auto 且盒子蓋滿
             視窗，地球放負 z-index 會被它擋掉所有點擊/拖曳，事件傳不到 canvas。 -->
        <div ref="containerEl" class="fixed inset-x-0 top-16 bottom-0 z-0" />

        <!-- 內容層整片 pointer-events-none 讓事件穿透到地球，只有實際面板收事件，
             面板之間的空白處可以直接拖曳轉動地球。 -->
        <main
            class="pointer-events-none relative z-10 min-h-[calc(100vh-4rem)]"
        >
            <!-- 未選國家：置中的世界層摘要，不擋地球主體 -->
            <div
                v-if="!selected"
                class="pointer-events-none flex min-h-[calc(100vh-4rem)] items-end justify-center p-6 md:items-center md:justify-start md:p-12"
            >
                <div
                    class="binary-glass pointer-events-auto max-w-sm rounded-2xl p-5"
                >
                    <p
                        class="binary-label text-[10px] text-[var(--binary-outline)] uppercase"
                    >
                        &gt; territory_graph
                    </p>
                    <h1
                        class="text-gradient-primary mt-2 text-2xl font-bold md:text-3xl"
                    >
                        世界行政區圖譜
                    </h1>
                    <p class="mt-2 text-xs text-[var(--binary-text-muted)]">
                        點擊地球上的國家,查看它底下的行政區資料。拖曳可旋轉地球。
                    </p>

                    <!-- 國家清單載入失敗時地球還是能轉，但點了不會有反應，要明講原因 -->
                    <div
                        v-if="countriesError"
                        class="mt-4 rounded-lg border border-[var(--binary-tertiary)]/40 p-3"
                    >
                        <p class="text-xs text-[var(--binary-tertiary)]">
                            國家資料載入失敗({{
                                countriesError
                            }}),地球可以轉動但無法點選。
                        </p>
                        <button
                            type="button"
                            class="binary-ghost-button mt-2 text-xs"
                            @click="loadCountries"
                        >
                            重新載入
                        </button>
                    </div>

                    <dl v-else class="mt-5 grid grid-cols-3 gap-3 text-center">
                        <div>
                            <dt
                                class="text-[10px] text-[var(--binary-outline)]"
                            >
                                國家
                            </dt>
                            <dd
                                class="text-lg font-bold text-[var(--binary-primary)]"
                            >
                                {{ worldStats.countries }}
                            </dd>
                        </div>
                        <div>
                            <dt
                                class="text-[10px] text-[var(--binary-outline)]"
                            >
                                有下層資料
                            </dt>
                            <dd
                                class="text-lg font-bold text-[var(--binary-primary)]"
                            >
                                {{ worldStats.withData }}
                            </dd>
                        </div>
                        <div>
                            <dt
                                class="text-[10px] text-[var(--binary-outline)]"
                            >
                                行政區總數
                            </dt>
                            <dd
                                class="text-lg font-bold text-[var(--binary-primary)]"
                            >
                                {{ formatNumber(worldStats.totalChildren) }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <!-- 選了國家：左側資料面板滑入（手機版從下方佔滿寬度） -->
            <div
                v-else
                class="pointer-events-none flex min-h-[calc(100vh-4rem)] items-end md:items-center"
            >
                <section
                    class="binary-glass pointer-events-auto max-h-[70vh] w-full overflow-y-auto rounded-2xl p-5 md:ml-8 md:max-h-[80vh] md:w-[26rem]"
                >
                    <button
                        type="button"
                        class="binary-ghost-button mb-4 text-xs"
                        @click="backToWorld"
                    >
                        ← 回到世界
                    </button>

                    <h2 class="text-2xl font-bold text-[var(--binary-text)]">
                        {{ selected.label }}
                    </h2>
                    <p class="mt-1 text-xs text-[var(--binary-text-muted)]">
                        {{ selected.iso_code }} ·
                        {{ selected.continent ?? '—' }} · 人口
                        {{ formatNumber(selected.population) }}
                    </p>

                    <div
                        class="mt-4 flex gap-4 border-y border-[var(--binary-outline-variant)] py-3 text-xs"
                    >
                        <div>
                            <span class="text-[var(--binary-outline)]"
                                >一級行政區</span
                            >
                            <span
                                class="ml-1 font-bold text-[var(--binary-primary)]"
                                >{{ children.length }}</span
                            >
                        </div>
                        <div v-if="gapCount">
                            <span class="text-[var(--binary-outline)]"
                                >下層資料缺口</span
                            >
                            <span
                                class="ml-1 font-bold text-[var(--binary-tertiary)]"
                                >{{ gapCount }}</span
                            >
                        </div>
                    </div>

                    <p
                        v-if="isLoading"
                        class="mt-4 text-xs text-[var(--binary-primary)]"
                    >
                        載入中...
                    </p>
                    <p v-else-if="loadError" class="mt-4 text-xs text-red-300">
                        {{ loadError }}
                    </p>
                    <p
                        v-else-if="!children.length"
                        class="mt-4 text-xs text-[var(--binary-text-muted)]"
                    >
                        這個國家目前沒有下層行政區資料。
                    </p>

                    <!-- 人口長條：純 CSS 寬度，不另外拉圖表套件 -->
                    <ul v-else class="mt-4 space-y-2">
                        <li v-for="c in topChildren" :key="c.qid">
                            <div
                                class="flex items-baseline justify-between gap-2 text-xs"
                            >
                                <span
                                    class="truncate text-[var(--binary-text)]"
                                    >{{ c.label }}</span
                                >
                                <span
                                    class="shrink-0 text-[10px] text-[var(--binary-outline)]"
                                    >{{ formatNumber(c.population) }}</span
                                >
                            </div>
                            <div
                                class="mt-1 h-1.5 w-full rounded-full bg-[var(--binary-surface-container)]"
                            >
                                <div
                                    class="h-full rounded-full bg-[var(--binary-primary)]"
                                    :style="{
                                        width: `${((c.population ?? 0) / maxPopulation) * 100}%`,
                                    }"
                                />
                            </div>
                        </li>
                    </ul>

                    <p
                        v-if="children.length > topChildren.length"
                        class="mt-3 text-[10px] text-[var(--binary-outline)]"
                    >
                        僅顯示人口前 {{ topChildren.length }} 名,共
                        {{ children.length }} 個
                    </p>
                </section>
            </div>
        </main>
    </AppLayout>
</template>

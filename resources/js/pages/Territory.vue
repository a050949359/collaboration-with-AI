<script setup lang="ts">
// Territory 行政區瀏覽（範例頁）。
// 版面概念：地球取代背景動畫鋪滿整個視窗，未選國家時置中、選了之後往右位移，
// 左側滑入資料面板。位移用 globe.gl 的 globeOffset（canvas 尺寸全程不變），
// 不是改容器寬度——改寬度每幀都要 resize WebGL renderer，會頓。
import { Head } from '@inertiajs/vue3';
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

/** 目前選取國家的行政區邊界 feature（GeoJSON，`id` 就是 QID）。 */
const adminFeatures = ref<any[]>([]);
/** 這個國家畫不出邊界：檔案不存在，或檔案裡的 QID 跟圖譜這一層對不起來。 */
const adminMissing = ref(false);
/** 目前點亮的行政區（地球上的 polygon 與左側清單共用這個狀態）。 */
const activeChildQid = ref<string | null>(null);

/** 國界 feature，全程不變；行政區邊界是疊在它上面另一組資料。 */
let worldFeatures: unknown[] = [];

/**
 * 換國家時的請求序號。子節點與邊界是兩個接力的非同步請求，使用者快速連點不同國家時
 * 先發的可能後到，沒有這個序號就會把上一國的資料蓋到現在選的國家上。
 */
let selectionToken = 0;

/**
 * 點到沒有對應圖譜資料的區域時的短暫提示。
 * 沒有這個的話會變成「點了完全沒反應」，使用者只能猜是不是壞了——
 * 圖譜沒資料（空 DB）或該國沒有 ISO 數字碼時都會走到這裡。
 */
const clickHint = ref('');
let clickHintTimer = 0;

function showClickHint(message: string) {
    clickHint.value = message;
    clearTimeout(clickHintTimer);
    clickHintTimer = window.setTimeout(() => {
        clickHint.value = '';
    }, 3200);
}

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

/** QID → 子節點，給 polygon 的 label／點擊回查用。 */
const childByQid = computed(() => {
    const map = new Map<string, Node>();

    for (const c of children.value) {
        map.set(c.qid, c);
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

/**
 * 主題色 + 透明度。polygon 的 cap/stroke 要吃帶 alpha 的顏色字串，
 * 而 themeColor() 回來的是 `#rrggbb`，這裡補上 alpha。
 */
function withAlpha(color: string, alpha: number): string {
    const m = /^#([\da-f]{2})([\da-f]{2})([\da-f]{2})$/i.exec(color);

    if (!m) {
        return color;
    }

    const [r, g, b] = m.slice(1).map((h) => parseInt(h, 16));

    return `rgba(${r},${g},${b},${alpha})`;
}

/** 行政區 polygon 的 id 是 QID（Q123…），國界是 ISO 數字碼，用這個分辨兩種圖層。 */
function isAdminPolygon(feat: any): boolean {
    return typeof feat?.id === 'string' && feat.id.startsWith('Q');
}

/** 國界 + 目前國家的行政區邊界疊成同一份 polygonsData（globe.gl 只有一個 polygon 圖層）。 */
function syncPolygons() {
    globeInstance?.polygonsData([...worldFeatures, ...adminFeatures.value]);
}

/**
 * 顏色 accessor 是純函式，globe.gl 不知道它依賴的 ref 變了，
 * 重新餵同一個 accessor 逼它整層重算（沿用既有做法）。
 */
function refreshPolygonStyles() {
    globeInstance
        ?.polygonCapColor(globeInstance.polygonCapColor())
        .polygonStrokeColor(globeInstance.polygonStrokeColor());
}

/**
 * 抓某國的行政區邊界。檔案由 scripts/build-admin-geojson.py 產生，一國一檔、
 * 檔名是國家 QID，內容同時含第一層與第二層（Natural Earth 的 admin-1 本來就混著兩層）。
 * 這裡只留「目前這層子節點真的有的 QID」，不然使用者還沒下鑽就會看到第二層的線。
 */
async function loadAdminGeometry(qid: string, token: number) {
    adminFeatures.value = [];
    adminMissing.value = false;

    try {
        const res = await fetch(`/geo/admin/${qid}.json`, {
            headers: { Accept: 'application/json' },
        });

        // 沒有這個國家的邊界檔是正常情況（來源涵蓋 193 國），不是錯誤
        if (!res.ok) {
            adminMissing.value = true;

            return;
        }

        const json = await res.json();
        const features = Array.isArray(json?.features) ? json.features : [];

        if (token !== selectionToken) {
            return;
        }

        adminFeatures.value = features.filter((f: any) =>
            childByQid.value.has(String(f?.id)),
        );
        adminMissing.value = adminFeatures.value.length === 0;
    } catch {
        // 邊界只是視覺加值，載不到就退回「只有國界」的樣子，不要影響資料面板
        adminMissing.value = true;
    } finally {
        if (token === selectionToken) {
            syncPolygons();
            refreshPolygonStyles();
        }
    }
}

/** 點亮某個行政區（清單列與地球 polygon 互相連動），有座標就把鏡頭帶過去。 */
function focusChild(child: Node) {
    activeChildQid.value =
        activeChildQid.value === child.qid ? null : child.qid;
    refreshPolygonStyles();

    if (activeChildQid.value && child.lat != null && globeInstance) {
        globeInstance.pointOfView(
            { lat: child.lat, lng: child.lng, altitude: 0.9 },
            700,
        );
    }
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
    const token = ++selectionToken;

    selected.value = country;
    children.value = [];
    adminFeatures.value = [];
    adminMissing.value = false;
    activeChildQid.value = null;
    loadError.value = '';
    isLoading.value = true;

    // 停自動旋轉：已經聚焦在特定目標，繼續轉會慢慢轉離焦點
    if (globeInstance) {
        globeInstance.controls().autoRotate = false;
        syncPolygons();
        refreshPolygonStyles();
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

        // 使用者已經換選別國，這份是慢到的舊回應，丟掉
        if (token !== selectionToken) {
            return;
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

        // 邊界要等子節點回來才抓：檔案裡同時含第一、二層，得先知道這層有哪些 QID
        await loadAdminGeometry(country.qid, token);
    } catch (error) {
        loadError.value =
            error instanceof Error ? error.message : 'Failed to load';
    } finally {
        if (token === selectionToken) {
            isLoading.value = false;
        }
    }
}

function backToWorld() {
    // 序號往前推，正在飛的子節點／邊界請求回來時就會被當成過期資料丟掉
    selectionToken++;
    selected.value = null;
    children.value = [];
    adminFeatures.value = [];
    adminMissing.value = false;
    activeChildQid.value = null;
    loadError.value = '';
    tweenOffset(0);

    if (globeInstance) {
        globeInstance.controls().autoRotate = true;
        syncPolygons();
        refreshPolygonStyles();
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
        // 行政區墊高一點點：跟國界同高的話兩層會 z-fighting 閃爍
        .polygonAltitude((feat: any) => (isAdminPolygon(feat) ? 0.012 : 0.006))
        .polygonCapColor((feat: any) => {
            if (isAdminPolygon(feat)) {
                return activeChildQid.value === feat.id
                    ? withAlpha(primary, 0.55)
                    : withAlpha(primary, 0.12);
            }

            // 有行政區邊界時就不要再鋪整國的白底，否則會把上面那層洗淡
            return isSelectedPolygon(feat) && !adminFeatures.value.length
                ? 'rgba(255,255,255,0.28)'
                : 'rgba(0,0,0,0)';
        })
        .polygonSideColor(() => 'rgba(0,0,0,0)')
        .polygonStrokeColor((feat: any) => {
            if (isAdminPolygon(feat)) {
                return activeChildQid.value === feat.id
                    ? '#ffffff'
                    : withAlpha(primary, 0.65);
            }

            return isSelectedPolygon(feat) ? '#ffffff' : primary;
        })
        .polygonLabel((feat: any) => {
            if (isAdminPolygon(feat)) {
                const child = childByQid.value.get(String(feat.id));

                if (!child) {
                    return '';
                }

                return child.population
                    ? `${child.label}（人口 ${child.population.toLocaleString()}）`
                    : child.label;
            }

            const c = byIsoNumeric.value.get(polygonIso(feat));

            return c ? `${c.label}（${c.child_count} 個一級行政區）` : '';
        })
        .onPolygonClick((feat: any) => {
            if (isAdminPolygon(feat)) {
                const child = childByQid.value.get(String(feat.id));

                if (child) {
                    focusChild(child);
                }

                return;
            }

            const c = byIsoNumeric.value.get(polygonIso(feat));

            if (!c) {
                // 對不上的兩種情況：圖譜整個沒資料，或該國沒有 ISO 數字碼
                // （解體歷史實體/爭議地區）。兩種都要講清楚，不要靜默。
                showClickHint(
                    countries.value.length === 0
                        ? '目前沒有國家資料，無法對應點選的區域'
                        : `${feat?.properties?.name ?? '這個區域'} 沒有對應的圖譜資料`,
                );

                return;
            }

            void selectCountry(c);
        })
        .pointOfView({ lat: 20, lng: 0, altitude: 2.4 }, 0);

    globeInstance.controls().autoRotate = true;
    globeInstance.controls().autoRotateSpeed = 0.25;

    resizeToContainer();

    // 自架的混合國界（110m 骨架 + 50m 獨有的小島），由 scripts/build-globe-geojson.py
    // 產生。110m 少了 61 個小島國／屬地的 feature（新加坡、馬爾他、馬爾地夫…），
    // 那些國家在圖譜裡有資料卻點不到；補完涵蓋率等同 50m，頂點只多 15%。
    const world = await fetch('/geo/countries-hybrid.json').then(
        (r) => r.json() as Promise<{ features: unknown[] }>,
    );

    worldFeatures = world.features;
    syncPolygons();
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
    clearTimeout(clickHintTimer);
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
            <!-- 點到沒有對應資料的區域時的浮動提示，3 秒後消失 -->
            <Transition name="hint">
                <p
                    v-if="clickHint"
                    class="binary-glass absolute inset-x-0 top-4 mx-auto w-fit rounded-full px-4 py-2 text-xs text-[var(--binary-text-muted)]"
                >
                    {{ clickHint }}
                </p>
            </Transition>

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

                    <!-- 資料是空的（例如本機 DB 沒匯入）也要講，不然使用者只會看到
                         一顆點不動的地球跟三個 0，無從判斷是壞了還是沒資料 -->
                    <p
                        v-else-if="!countries.length"
                        class="mt-4 rounded-lg border border-[var(--binary-outline-variant)] p-3 text-xs text-[var(--binary-text-muted)]"
                    >
                        目前資料庫沒有國家資料,地球可以轉動但無法點選。
                    </p>

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

                        <!-- 邊界圖資是逐國補的，有幾個畫得出來要講清楚，
                             不然使用者會以為地球上少畫的那些是壞掉了 -->
                        <div v-if="adminFeatures.length">
                            <span class="text-[var(--binary-outline)]"
                                >有邊界圖</span
                            >
                            <span
                                class="ml-1 font-bold text-[var(--binary-primary)]"
                                >{{ adminFeatures.length }}</span
                            >
                        </div>
                        <div
                            v-else-if="adminMissing && children.length"
                            class="text-[var(--binary-outline)]"
                        >
                            尚無行政區邊界圖
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

                    <!-- 人口長條：純 CSS 寬度，不另外拉圖表套件。
                         整列可點：點了會在地球上點亮對應的行政區並把鏡頭帶過去，
                         反過來點地球上的行政區也會點亮這裡的同一列。 -->
                    <ul v-else class="mt-4 space-y-2">
                        <li v-for="c in topChildren" :key="c.qid">
                            <button
                                type="button"
                                class="w-full cursor-pointer rounded-lg px-2 py-1 text-left transition-colors"
                                :class="
                                    activeChildQid === c.qid
                                        ? 'bg-[var(--binary-surface-high)]'
                                        : 'hover:bg-[var(--binary-surface-container)]'
                                "
                                @click="focusChild(c)"
                            >
                                <div
                                    class="flex items-baseline justify-between gap-2 text-xs"
                                >
                                    <span
                                        class="truncate"
                                        :class="
                                            activeChildQid === c.qid
                                                ? 'font-bold text-[var(--binary-primary)]'
                                                : 'text-[var(--binary-text)]'
                                        "
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
                            </button>
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

<style scoped>
.hint-enter-active,
.hint-leave-active {
    transition:
        opacity 0.25s ease,
        transform 0.25s ease;
}
.hint-enter-from,
.hint-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}
</style>

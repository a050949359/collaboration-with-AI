<script setup lang="ts">
// Territory 行政區瀏覽（範例頁）。
// 版面概念：地球取代背景動畫鋪滿整個視窗，未選國家時置中、選了之後往右位移，
// 左側滑入資料面板。位移用 globe.gl 的 globeOffset（canvas 尺寸全程不變），
// 不是改容器寬度——改寬度每幀都要 resize WebGL renderer，會頓。
import { Head } from '@inertiajs/vue3';
import * as THREE from 'three';
import { computed, onMounted, onUnmounted, ref, shallowRef } from 'vue';
import FlatMap from '../components/territory/FlatMap.vue';
import AppLayout from '../layouts/AppLayout.vue';
import { api } from '../lib/routes';
import { themeColor } from '../lib/theme-color';

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

/**
 * polygon 的 cap 材質，全部 polygon 共用這兩份。
 *
 * three-globe 是逐「單一 polygon 塊」建物件的（MultiPolygon 會被拆開），這份國界
 * 拆完約 284 塊，預設每塊都會自己 new 一份材質。改成共用實例後材質從 284 份變 2 份，
 * draw call 之間不必再切換材質狀態。
 *
 * 未選取那份用 `colorWrite: false`：畫面上跟原本的全透明一樣看不見，但它不是
 * 「透明物件」，所以不進透明佇列、不做混合、也不必每幀重新深度排序。
 * 點擊偵測是對 mesh 做 raycast、不看材質，所以照常可以點。
 */
const idleCapMaterial = new THREE.MeshBasicMaterial({
    colorWrite: false,
    depthWrite: false,
});
const selectedCapMaterial = new THREE.MeshBasicMaterial({
    color: 0xffffff,
    transparent: true,
    opacity: 0.28,
    depthWrite: false,
});

let Globe: any = null;
let globeInstance: any = null;
let offsetRaf = 0;

/**
 * 世界層的 feature。
 *
 * 行政區**不會**進到 three-globe，它只存在於 2D 攤平層（見 FlatMap.vue）。
 * 早期版本會先把行政區當 polygon 疊上地球「浮起來」，再按第二顆按鈕攤平——
 * 那等於同一件事演兩次，已經拿掉。少掉的不只是一個步驟：每次下鑽本來要為
 * 一百多塊建 ConicPolygonGeometry（法國 136 塊），現在一塊都不必建。
 */
let worldFeatures: any[] = [];

/**
 * 國界 polygon 浮出球面的高度。
 *
 * 攤平的第 0 幀要用**同一個值**去算螢幕座標，算出來的輪廓才會剛好疊在國家的
 * cap 上；用 0 的話會陷進地球紋理裡，差一點點但看得出來。
 */
const surfaceAltitude = 0.006;

const countries = ref<Country[]>([]);
const selected = ref<Country | null>(null);
const children = ref<Node[]>([]);
const isLoading = ref(false);
const loadError = ref('');
/** 國家清單載入失敗（跟「某國子節點載入失敗」分開，這個會讓整顆地球點不動） */
const countriesError = ref('');

interface AdminStats {
    /** 第一層 feature 數 */
    l1: number;
    /** 第二層 feature 數（目前只當統計，還沒做到第三層） */
    l2: number;
    parts: number;
    vertices: number;
    /**
     * 本土那一群的外接框 [w, s, e, n]，鏡頭用。
     * ⚠️ 經度是展開過的，可能超出 ±180（俄羅斯 190.3、斐濟 180.2）。
     */
    bbox: [number, number, number, number];
    kb: number;
}

/** 哪些國家有行政區幾何可以下鑽（public/geo/admin/index.json，由腳本產生） */
const adminIndex = ref<Record<string, AdminStats>>({});
const isDrilling = ref(false);
/** 攤平後點到的那一塊行政區 QID */
const activeRegionQid = ref<string | null>(null);
/** 是否已經攤平成 2D。退場有動畫，所以卸載要等 FlatMap 回報 exited */
const isFlat = ref(false);
const flatMapEl = ref<InstanceType<typeof FlatMap> | null>(null);
/**
 * 交接那一刻的行政區 feature 快照，交給 FlatMap 當 props。
 *
 * 一定要 shallowRef：一般的 ref 會把整棵幾何（法國 15,040 個座標陣列）包成
 * reactive proxy，除了白花時間，proxy 後的物件跟 three-globe 手上那批**不是
 * 同一個實例**，digest 會把已經建好的板塊當成新資料整批重建。
 */
const flatFeatures = shallowRef<any[]>([]);
/** 視窗寬度。panelInset 是 computed，要有個會變的來源才會跟著 resize 重算。 */
const viewportWidth = ref(0);

/** 目前這個國家能不能下鑽（有被選取、且索引裡有第一層幾何） */
const canDrill = computed(
    () => !!selected.value && !!adminIndex.value[selected.value.qid],
);

/** 點到的那塊行政區要顯示的名字 */
const regionLabel = computed(() => {
    const qid = activeRegionQid.value;

    if (!qid) {
        return '';
    }

    return (
        children.value.find((c) => c.qid === qid)?.label ??
        flatFeatures.value.find((f) => f.id === qid)?.properties?.name ??
        qid
    );
});

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

/**
 * 行政區顯示名：幾何檔有些 feature 沒有 name，用圖譜的 children 補。
 * 兩邊都沒有就回空字串，不要拿 QID 充數——地圖上標一個「Q15104」比留白更糟，
 * 面板那邊才適合顯示 QID（那裡的用途是讓人查得到是哪一筆）。
 */
function regionName(feat: any): string {
    const fromGraph = children.value.find((c) => c.qid === feat?.id);

    // 圖譜裡有些節點的 label 直接存成自己的 QID（例如 Q15104／普羅旺斯-阿爾卑斯-
    // 蔚藍海岸），那是匯入時漏掉名稱，不是真的叫這個名字。當成沒有名稱處理。
    const label =
        fromGraph && fromGraph.label !== fromGraph.qid ? fromGraph.label : null;

    return label ?? feat?.properties?.name ?? '';
}

/** 2D 層的填色量值。幾何檔沒有人口，一律從圖譜的 children 取。 */
function regionPopulation(feat: any): number | null {
    return children.value.find((c) => c.qid === feat?.id)?.population ?? null;
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

/**
 * 資料面板實際佔掉的畫面，給 2D 地圖避開用。
 *
 * 3D 那邊是整顆球往右推（globeOffset），2D 這邊不推畫面、改成把地圖 fit 在剩下的
 * 矩形裡——平面圖沒有「球心」的概念，推位移只會讓它偏出畫面。
 * 數字對應面板的 `md:w-[26rem]` + `md:ml-8`，再加一點呼吸空間。
 */
const panelInset = computed(() => {
    const isNarrow = viewportWidth.value < 768;

    return {
        left: isNarrow ? 0 : 26 * 16 + 32 + 24,
        bottom: isNarrow ? window.innerHeight * 0.45 : 0,
    };
});

/** 選中時地球往右讓出左側面板空間；手機版空間不夠，改成不位移（面板走底部）。 */
function offsetForSelection(): number {
    if (window.innerWidth < 768) {
        return 0;
    }

    return window.innerWidth * 0.2;
}

/**
 * 逼 three-globe 重跑一次 accessor。
 *
 * 這些 accessor 讀的是 Vue 的 ref（selected），值變了 globe 並不知道；
 * 把 accessor 原封不動再設回去就會觸發一次 digest。材質只是換參考，不重建幾何。
 */
function refreshStyles() {
    if (!globeInstance) {
        return;
    }

    globeInstance
        .polygonCapMaterial(globeInstance.polygonCapMaterial())
        .polygonStrokeColor(globeInstance.polygonStrokeColor());
}

/**
 * 依 bbox 把鏡頭擺到該國上方：框愈大拉愈遠。
 *
 * bbox 來自 index.json，是「本土那一群」的框，而且**經度是展開過的**，
 * 可能超出 ±180（俄羅斯的東界是 190.3、斐濟是 180.2），這樣跨換日線的國家才算得出
 * 連續的中心點與跨幅。算完中心點要自己繞回 -180..180 再交給 pointOfView。
 */
function focusOnBbox(bbox: [number, number, number, number]) {
    const [west, south, east, north] = bbox;
    const lat = (south + north) / 2;
    const lng = (((((west + east) / 2 + 180) % 360) + 360) % 360) - 180;

    // 經度差要乘 cos(lat) 才是實際跨幅，否則高緯度國家會被誤判成很寬而拉太遠
    const spanLat = north - south;
    const spanLng = (east - west) * Math.cos((lat * Math.PI) / 180);
    const span = Math.max(spanLat, spanLng);

    globeInstance?.pointOfView(
        { lat, lng, altitude: Math.min(2.2, Math.max(0.35, span / 22)) },
        900,
    );
}

/**
 * 展開行政區：抓幾何，直接進 2D 攤平層。
 *
 * 這裡**不動鏡頭**。攤平的起點是「這些邊界現在畫在螢幕上的位置」，鏡頭若還在飛，
 * 快照下來的起點跟底下的地球就對不上了；而且收合時倒帶回去也剛好落在原位，
 * 不必再飛一趟。鏡頭該就位的時機是「選取國家」那一步（見 selectCountry）。
 */
async function openFlatMap(country: Country) {
    if (!adminIndex.value[country.qid] || isDrilling.value || isFlat.value) {
        return;
    }

    isDrilling.value = true;

    try {
        const res = await fetch(`/geo/admin/${country.qid}.json`);

        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }

        const payload = (await res.json()) as { features: any[] };

        // level 0 是國家自己（跟世界層重複），level 2 是第二層（還沒做到那一步）
        flatFeatures.value = payload.features.filter(
            (f) => f?.properties?.level === 1,
        );
        activeRegionQid.value = null;
        isFlat.value = true;
    } catch (error) {
        flatFeatures.value = [];
        showClickHint(
            `行政區邊界載入失敗（${error instanceof Error ? error.message : '未知錯誤'}）`,
        );
    } finally {
        isDrilling.value = false;
    }
}

/**
 * 「這個經緯度，現在畫在螢幕的哪個 px」——用地球自己的相機算。
 *
 * 這是 2D 轉場不會跳的關鍵：回傳的就是 three.js 當下把該點畫到的位置，
 * 所以攤平動畫的第 0 幀跟 WebGL 畫面在數學上完全重合。
 * 用 d3 的 geoOrthographic 去近似相機也行，但那是拿正射當透視，實測跨度 15° 的
 * 國家邊緣會差到 7.85%，交接瞬間看得出來抖一下。
 *
 * 相機的 setViewOffset（globeOffset 讓地球右移那件事）已經算在 projectionMatrix
 * 裡，所以這裡不必額外補償位移。
 */
function projectFromGlobe(lng: number, lat: number): [number, number] | null {
    if (!globeInstance) {
        return null;
    }

    const { x, y, z } = globeInstance.getCoords(lat, lng, surfaceAltitude);
    const point = new THREE.Vector3(x, y, z);
    const toCamera = globeInstance.camera().position.clone().sub(point);

    // 背面剔除。getScreenCoords 對球背面的點照樣吐得出座標，不擋的話攤平會從
    // 地球背面拉出幾條線。地表法線（球心→該點）與「該點→相機」同向才看得見。
    if (point.clone().normalize().dot(toCamera.normalize()) <= 0) {
        return null;
    }

    const screen = globeInstance.getScreenCoords(lat, lng, surfaceAltitude);

    return screen ? [screen.x, screen.y] : null;
}

/** 收回地球：先讓 2D 層倒帶回球面，播完才卸載（見 onFlatExited）。 */
function unflatten() {
    globeInstance?.resumeAnimation();
    flatMapEl.value?.exit();
}

function onFlatReady(info: { shown: number; dropped: number }) {
    // 海外屬地離本土太遠，一起框進畫面本土會縮成一點，所以 2D 層只畫本土那一團。
    // 但不能默默少掉——面板列表裡明明有，畫面上卻找不到。
    if (info.dropped > 0) {
        showClickHint(`平面圖只顯示本土，另有 ${info.dropped} 個海外屬地未畫`);
    }
}

function onFlatDone() {
    // 攤平後地球整片透明，還讓它每幀重畫沒有意義
    globeInstance?.pauseAnimation();
}

function onFlatExited() {
    isFlat.value = false;
    flatFeatures.value = [];

    if (exitThenWorld) {
        exitThenWorld = false;
        backToWorld();
    }
}

/**
 * 麵包屑上的「世界」：從平面圖一次跳兩層。
 *
 * 不能直接 backToWorld——那會把 2D 層硬砍掉。先播完摺疊動畫，回到地球之後才退回
 * 世界層（見 onFlatExited）。
 */
let exitThenWorld = false;

function goWorld() {
    if (isFlat.value) {
        exitThenWorld = true;
        unflatten();

        return;
    }

    backToWorld();
}

/** 不播退場動畫直接收掉 2D 層。用在「跳過中間層級」的情況（換國家、回世界）。 */
function closeFlatNow() {
    if (!isFlat.value) {
        return;
    }

    globeInstance?.resumeAnimation();
    onFlatExited();
}

async function selectCountry(country: Country) {
    // 點已經選取的同一國＝直接展開行政區（面板上那顆按鈕才是正式入口）
    if (selected.value?.qid === country.qid) {
        void openFlatMap(country);

        return;
    }

    // 換國家：上一國的平面圖直接收掉，不播退場動畫
    closeFlatNow();

    selected.value = country;
    children.value = [];
    loadError.value = '';
    isLoading.value = true;

    // 停自動旋轉：已經聚焦在特定目標，繼續轉會慢慢轉離焦點
    if (globeInstance) {
        globeInstance.controls().autoRotate = false;
        refreshStyles();
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

        // 鏡頭就位。這是**唯一**會動鏡頭的地方——展開行政區時不再飛，
        // 因為攤平的起點就是「邊界現在畫在螢幕上的位置」，鏡頭還在飛就對不上。
        //
        // 有幾何索引就用本土 bbox（框大小決定拉多遠）；沒有才退回子節點的座標。
        // 子節點是「第一個有 lat 的」，那其實是任意一筆，可能是個海外小區——
        // 法國會飛到留尼旺去，所以能用 bbox 就不要用它。
        const bbox = adminIndex.value[country.qid]?.bbox;
        const anchor = children.value.find((c) => c.lat != null);

        if (bbox) {
            focusOnBbox(bbox);
        } else if (anchor && globeInstance) {
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
    closeFlatNow();
    selected.value = null;
    children.value = [];
    loadError.value = '';
    activeRegionQid.value = null;
    tweenOffset(0);

    if (globeInstance) {
        globeInstance.controls().autoRotate = true;
        refreshStyles();
        globeInstance.pointOfView({ altitude: 2.4 }, 800);
    }
}

/** Esc 一次退一層：平面圖 → 國家 → 世界 */
function onKeydown(event: KeyboardEvent) {
    if (event.key !== 'Escape') {
        return;
    }

    if (isFlat.value) {
        unflatten();
    } else if (selected.value) {
        backToWorld();
    }
}

function resizeToContainer() {
    viewportWidth.value = window.innerWidth;

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
        .backgroundImageUrl('/images/globe/night-sky.jpg')
        // 夜間版：這顆球背後要壓文字跟卡片，白天版太亮會跟內容搶對比
        .globeImageUrl('/images/globe/earth-night.jpg')
        .showAtmosphere(true)
        .atmosphereColor(primary)
        .atmosphereAltitude(0.16)
        .polygonAltitude(surfaceAltitude)
        .polygonCapMaterial((feat: any) =>
            isSelectedPolygon(feat) ? selectedCapMaterial : idleCapMaterial,
        )
        // 側牆設成 falsy 讓 three-globe 連幾何都不建（includeSides=false）。
        // 它原本就是全透明看不見的，卻仍然是三角形、還多佔一份材質。
        .polygonSideColor(() => false)
        .polygonStrokeColor((feat: any) =>
            isSelectedPolygon(feat) ? '#ffffff' : primary,
        )
        .polygonLabel((feat: any) => {
            const c = byIsoNumeric.value.get(polygonIso(feat));

            return c ? `${c.label}（${c.child_count} 個一級行政區）` : '';
        })
        .onPolygonClick((feat: any) => {
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
    // 已經是 GeoJSON，不需要 topojson.feature() 轉換。
    //
    // ⚠️ 不要直接換成完整的 50m：瓶頸不是下載（本機實測 6 ms）也不是 JSON.parse
    // （8 ms），而是 three-globe 建幾何——它把 MultiPolygon 拆成「塊」逐塊建
    // ConicPolygonGeometry，50m 是 1,616 塊／99,539 頂點，混合版是 436 塊／12,218
    // 頂點。這段是同一個 task 跑完才還給瀏覽器，換 50m 實測單一長任務 5.6 秒
    // （headless CPU），期間整頁凍住、連進度條都動不了。
    const world = await fetch('/geo/countries-hybrid.json').then(
        (r) => r.json() as Promise<{ features: any[] }>,
    );

    worldFeatures = world.features;
    globeInstance.polygonsData(worldFeatures);
}

/**
 * 下鑽索引。18 KB，跟世界層一起載；沒有它就只能每點一國先去 fetch 看會不會 404，
 * 而且面板上那顆「展開行政區」要先畫出來才知道該不該畫。
 * 載不到不算致命——只是所有國家都不給下鑽入口，國家層照常能用。
 */
async function loadAdminIndex() {
    try {
        const res = await fetch('/geo/admin/index.json');

        if (res.ok) {
            adminIndex.value = await res.json();
        }
    } catch {
        adminIndex.value = {};
    }
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
    viewportWidth.value = window.innerWidth;
    await Promise.all([loadCountries(), loadAdminIndex()]);
    await initGlobe();
    window.addEventListener('resize', resizeToContainer);
    window.addEventListener('keydown', onKeydown);
});

onUnmounted(() => {
    clearTimeout(clickHintTimer);
    cancelAnimationFrame(offsetRaf);
    window.removeEventListener('resize', resizeToContainer);
    window.removeEventListener('keydown', onKeydown);
    globeInstance?._destructor?.();
    globeInstance = null;
    worldFeatures = [];
    idleCapMaterial.dispose();
    selectedCapMaterial.dispose();
});
</script>

<template>
    <Head title="Territory" />
    <AppLayout disable-background>
        <!-- 地球鋪滿視窗當背景層（取代主題背景動畫），內容疊在上面。
             z-index 不能用負值：.binary-page 是 relative、pointer-events auto 且盒子蓋滿
             視窗，地球放負 z-index 會被它擋掉所有點擊/拖曳，事件傳不到 canvas。 -->
        <!-- 攤平後地球整片淡出。轉場的第 0 幀兩層是重合的，所以這段淡出看起來
             不像換頁，比較像線條被抽乾淨。pointer-events 也要一起關掉，否則
             看不見的球還在吃拖曳。 -->
        <div
            ref="containerEl"
            class="fixed inset-x-0 top-16 bottom-0 z-0 transition-opacity duration-500"
            :class="isFlat ? 'pointer-events-none opacity-0' : 'opacity-100'"
        />

        <div v-if="isFlat" class="fixed inset-x-0 top-16 bottom-0 z-0">
            <FlatMap
                ref="flatMapEl"
                :features="flatFeatures"
                :project="projectFromGlobe"
                :active-qid="activeRegionQid"
                :label-of="regionName"
                :value-of="regionPopulation"
                :inset-left="panelInset.left"
                :inset-bottom="panelInset.bottom"
                @select="activeRegionQid = $event"
                @ready="onFlatReady"
                @done="onFlatDone"
                @exited="onFlatExited"
            />
        </div>

        <!-- 內容層整片 pointer-events-none 讓事件穿透到地球，只有實際面板收事件，
             面板之間的空白處可以直接拖曳轉動地球。 -->
        <main
            class="pointer-events-none relative z-10 min-h-[calc(100vh-4rem)]"
        >
            <!-- 麵包屑：固定在左上角，**不隨面板移動**。
                 它取代了原本面板裡那顆退回鈕——現在有三層了，「回到地球／回到世界」
                 只表達「退一步」，看不出自己在哪一層、也沒辦法一次跳兩層。
                 世界層不顯示：那裡只會寫一個「世界」，跟旁邊的大標重複。 -->
            <Transition name="breadcrumb">
                <nav
                    v-if="selected"
                    aria-label="所在層級"
                    class="absolute top-4 left-0 z-10 flex items-center gap-1.5 px-6 text-[11px] md:pl-8"
                >
                    <button
                        type="button"
                        class="pointer-events-auto text-[var(--binary-outline)] transition hover:text-[var(--binary-primary)]"
                        @click="goWorld"
                    >
                        世界
                    </button>
                    <span class="text-[var(--binary-outline-variant)]">›</span>

                    <!-- 已經攤平時「國家」是上一層（可點回去），否則它就是目前位置 -->
                    <button
                        v-if="isFlat"
                        type="button"
                        class="pointer-events-auto text-[var(--binary-outline)] transition hover:text-[var(--binary-primary)]"
                        @click="unflatten"
                    >
                        {{ selected.label }}
                    </button>
                    <span v-else class="font-bold text-[var(--binary-text)]">
                        {{ selected.label }}
                    </span>

                    <template v-if="isFlat">
                        <span class="text-[var(--binary-outline-variant)]"
                            >›</span
                        >
                        <span class="font-bold text-[var(--binary-text)]">
                            行政區
                        </span>
                    </template>
                </nav>
            </Transition>

            <!-- 點到沒有對應資料的區域時的浮動提示，3 秒後消失 -->
            <Transition name="hint">
                <p
                    v-if="clickHint"
                    class="binary-glass absolute inset-x-0 top-4 mx-auto w-fit rounded-full px-4 py-2 text-xs text-[var(--binary-text-muted)]"
                >
                    {{ clickHint }}
                </p>
            </Transition>

            <!-- 未選國家：左下角的說明。
                 刻意不用 binary-glass：星空底本來就夠暗，加了玻璃面板在純黑上只會
                 多出一個灰色矩形的邊，看起來像浮在空地裡的卡片。擺左下而不是垂直
                 置中，是為了把中線讓給地球，順便填掉那塊死角、跟地球拉出對角線。 -->
            <!-- ⚠️ 兩塊都要 absolute 疊在同一格。轉場期間兩者同時存在，若留在一般
                 流程裡會上下堆成兩倍高，把畫面往下推、還長出捲軸。 -->
            <Transition name="world-intro">
                <div
                    v-if="!selected"
                    class="pointer-events-none absolute inset-0 flex items-end px-6 pb-8 md:px-12 md:pb-12"
                >
                    <div class="pointer-events-auto max-w-md">
                        <p
                            class="binary-label text-[10px] text-[var(--binary-outline)] uppercase"
                        >
                            &gt; territory_graph
                        </p>
                        <h1
                            class="text-gradient-primary mt-1 text-3xl font-bold md:text-4xl"
                        >
                            世界行政區圖譜
                        </h1>
                        <p
                            class="mt-2 text-xs text-[var(--binary-text-muted)] md:text-sm"
                        >
                            拖曳旋轉地球,點擊國家查看它底下的行政區。
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

                        <!-- 橫排＋靠左，跟上面的標題同一條左緣；原本的三欄置中在沒有
                         盒子框住時會散掉，對不到任何東西 -->
                        <dl
                            v-else
                            class="mt-5 flex gap-8 border-t border-[var(--binary-outline-variant)] pt-4"
                        >
                            <div>
                                <dt
                                    class="binary-label text-[10px] text-[var(--binary-outline)] uppercase"
                                >
                                    國家
                                </dt>
                                <dd
                                    class="mt-0.5 text-xl font-bold text-[var(--binary-primary)] tabular-nums"
                                >
                                    {{ worldStats.countries }}
                                </dd>
                            </div>
                            <div>
                                <dt
                                    class="binary-label text-[10px] text-[var(--binary-outline)] uppercase"
                                >
                                    有下層資料
                                </dt>
                                <dd
                                    class="mt-0.5 text-xl font-bold text-[var(--binary-primary)] tabular-nums"
                                >
                                    {{ worldStats.withData }}
                                </dd>
                            </div>
                            <div>
                                <dt
                                    class="binary-label text-[10px] text-[var(--binary-outline)] uppercase"
                                >
                                    行政區總數
                                </dt>
                                <dd
                                    class="mt-0.5 text-xl font-bold text-[var(--binary-primary)] tabular-nums"
                                >
                                    {{ formatNumber(worldStats.totalChildren) }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </Transition>

            <!-- 選了國家：左側資料面板滑入（手機版從下方佔滿寬度） -->
            <Transition name="country-panel">
                <div
                    v-if="selected"
                    class="pointer-events-none absolute inset-0 flex items-end md:items-center md:pt-12"
                >
                    <section
                        class="binary-glass pointer-events-auto max-h-[70vh] w-full overflow-y-auto rounded-2xl p-5 md:ml-8 md:max-h-[80vh] md:w-[26rem]"
                    >
                        <!-- 退回鍵搬到畫面左上角的麵包屑了（見 <main> 開頭）：
                             它原本是一顆藥丸鈕，在面板最上面吃掉一整列只為了一個
                             低頻動作，把真正要讀的國名往下推。 -->
                        <h2
                            class="text-2xl font-bold text-[var(--binary-text)]"
                        >
                            {{ selected.label }}
                        </h2>
                        <p class="mt-1 text-xs text-[var(--binary-text-muted)]">
                            {{ selected.iso_code }} ·
                            {{ selected.continent ?? '—' }} · 人口
                            {{ formatNumber(selected.population) }}
                        </p>

                        <div
                            class="mt-4 flex items-center gap-4 border-y border-[var(--binary-outline-variant)] py-3 text-xs"
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

                            <!-- 正式入口。再點一次地球上同一國也會展開，但那是隱藏的
                             快捷鍵，不能當唯一入口——使用者不會知道要點第二次。 -->
                            <!-- binary-button 是 w-full 的，放在這一列會把標籤擠到換行，
                             這裡要的是行內動作，所以走 ghost 版再補一圈主色邊框 -->
                            <button
                                v-if="canDrill && !isFlat"
                                type="button"
                                class="binary-ghost-button ml-auto shrink-0 border border-[var(--binary-primary)]/50 py-1 disabled:opacity-50"
                                :disabled="isDrilling"
                                @click="selected && openFlatMap(selected)"
                            >
                                {{ isDrilling ? '載入中…' : '展開行政區' }}
                            </button>
                            <span
                                v-else-if="isFlat"
                                class="ml-auto truncate text-[10px] text-[var(--binary-outline)]"
                            >
                                {{
                                    activeRegionQid
                                        ? regionLabel
                                        : '點區塊看名稱'
                                }}
                            </span>
                        </div>

                        <p
                            v-if="isLoading"
                            class="mt-4 text-xs text-[var(--binary-primary)]"
                        >
                            載入中...
                        </p>
                        <p
                            v-else-if="loadError"
                            class="mt-4 text-xs text-red-300"
                        >
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
            </Transition>
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

/* 世界層說明往下淡出（它本來就在畫面底部，往下退最自然），國家面板同時從左邊
   滑進來。兩段刻意重疊播放，不用 out-in 排隊——地球那邊的位移與鏡頭是 600/800ms
   的 tween，面板要在地球還在動的時候就到位，才像同一件事。 */
.world-intro-enter-active,
.world-intro-leave-active {
    transition:
        opacity 0.35s ease,
        transform 0.35s ease;
}
.world-intro-enter-from,
.world-intro-leave-to {
    opacity: 0;
    transform: translateY(16px);
}

.country-panel-enter-active,
.country-panel-leave-active {
    transition:
        opacity 0.4s ease,
        transform 0.4s cubic-bezier(0.22, 1, 0.36, 1);
}
.country-panel-enter-from,
.country-panel-leave-to {
    opacity: 0;
    transform: translateX(-32px);
}

/* 麵包屑是固定錨點，進出只用淡入淡出，不跟著滑動——會動的錨點就不是錨點了 */
.breadcrumb-enter-active,
.breadcrumb-leave-active {
    transition: opacity 0.3s ease;
}
.breadcrumb-enter-from,
.breadcrumb-leave-to {
    opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
    .world-intro-enter-active,
    .world-intro-leave-active,
    .country-panel-enter-active,
    .country-panel-leave-active,
    .breadcrumb-enter-active,
    .breadcrumb-leave-active {
        transition-duration: 0.01ms;
    }
    .world-intro-enter-from,
    .world-intro-leave-to,
    .country-panel-enter-from,
    .country-panel-leave-to {
        transform: none;
    }
}
</style>

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
import { themeColor, withAlpha } from '../lib/theme-color';

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

/**
 * 下鑽後浮起的行政區塊材質。這兩份跟上面不同，是真的要看得見的：
 * 浮起來的板塊如果沒有 cap 顏色，畫面上只會有一圈線，看不出「一塊一塊」。
 */
const regionCapMaterial = new THREE.MeshBasicMaterial({
    color: 0xffffff,
    transparent: true,
    opacity: 0.1,
    depthWrite: false,
});
const regionActiveCapMaterial = new THREE.MeshBasicMaterial({
    color: 0xffffff,
    transparent: true,
    opacity: 0.34,
    depthWrite: false,
});

let Globe: any = null;
let globeInstance: any = null;
let offsetRaf = 0;

/**
 * 世界層的 feature。**必須是同一批物件實例**，不能每次重新 map／展開。
 *
 * three-globe 的 digest 是 d3 式 enter/update，key 取自它蓋在 datum 上的 `__id`。
 * 下鑽時是把行政區「追加」進同一個陣列（見 applyPolygons），只要世界層傳的還是
 * 這批原物件，它們就落在 update 分支、一根三角形都不會重建；退出下鑽同理。
 * 若改成整個換掉，世界層 436 塊會被 dispose 再重建，回來時就是一次凍結。
 */
let worldFeatures: any[] = [];
/** 目前浮起中的行政區 feature（下鑽時才有東西） */
let regionFeatures: any[] = [];

/**
 * 行政區浮起的高度。2D 交接時要用同一個值去算螢幕座標，不然攤平的第 0 幀
 * 會對到球面而不是浮起後的位置，差一點點但看得出來。
 */
const regionAltitude = 0.03;

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
/** 已下鑽的國家 QID；null 代表還在國家層 */
const drilledQid = ref<string | null>(null);
const isDrilling = ref(false);
/** 下鑽後點到的那一塊行政區 QID */
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
        regionFeatures.find((f) => f.id === qid)?.properties?.name ??
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
 * 把目前該畫的 polygon 交給 globe。
 *
 * 一定要走「世界層原物件 + 行政區」的組合，不要用 map／filter 產生新物件，
 * 理由見 worldFeatures 的註解。
 */
function applyPolygons() {
    globeInstance?.polygonsData(
        regionFeatures.length
            ? [...worldFeatures, ...regionFeatures]
            : worldFeatures,
    );
}

/**
 * 逼 three-globe 重跑一次 accessor。
 *
 * 這些 accessor 讀的是 Vue 的 ref（selected / activeRegionQid），值變了 globe 並不
 * 知道；把 accessor 原封不動再設回去就會觸發一次 digest。altitude 是 scale tween、
 * 材質只是換參考，所以這趟不重建幾何——除非側牆的有無改變（見 polygonSideColor）。
 */
function refreshStyles() {
    if (!globeInstance) {
        return;
    }

    globeInstance
        .polygonCapMaterial(globeInstance.polygonCapMaterial())
        .polygonStrokeColor(globeInstance.polygonStrokeColor())
        .polygonAltitude(globeInstance.polygonAltitude());
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

/** 下鑽：抓該國的行政區幾何，追加到 polygon 層並浮起來。 */
async function drillIn(country: Country) {
    const stats = adminIndex.value[country.qid];

    if (!stats || isDrilling.value) {
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
        regionFeatures = payload.features.filter(
            (f) => f?.properties?.level === 1,
        );

        // 蓋一個自己的旗標，之後 accessor 就不必猜「這是國家還是行政區」
        for (const feature of regionFeatures) {
            feature.__region = true;
        }

        drilledQid.value = country.qid;
        activeRegionQid.value = null;

        applyPolygons();
        focusOnBbox(stats.bbox);
    } catch (error) {
        regionFeatures = [];
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

    const { x, y, z } = globeInstance.getCoords(lat, lng, regionAltitude);
    const point = new THREE.Vector3(x, y, z);
    const toCamera = globeInstance.camera().position.clone().sub(point);

    // 背面剔除。getScreenCoords 對球背面的點照樣吐得出座標，不擋的話攤平會從
    // 地球背面拉出幾條線。地表法線（球心→該點）與「該點→相機」同向才看得見。
    if (point.clone().normalize().dot(toCamera.normalize()) <= 0) {
        return null;
    }

    const screen = globeInstance.getScreenCoords(lat, lng, regionAltitude);

    return screen ? [screen.x, screen.y] : null;
}

/** 攤平：把目前浮起的行政區交給 2D 層，地球淡出並暫停。 */
function flatten() {
    if (!regionFeatures.length) {
        return;
    }

    flatFeatures.value = regionFeatures;
    isFlat.value = true;
}

/** 收回 3D：先讓 2D 層倒帶回球面，播完才卸載（見 onFlatExited）。 */
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
}

/** 不播退場動畫直接收掉 2D 層。用在「跳過中間層級」的情況（換國家、回世界）。 */
function closeFlatNow() {
    if (!isFlat.value) {
        return;
    }

    globeInstance?.resumeAnimation();
    onFlatExited();
}

/** 收起行政區，回到國家層。世界層不動，所以這一步是零重建。 */
function drillOut() {
    closeFlatNow();
    regionFeatures = [];
    drilledQid.value = null;
    activeRegionQid.value = null;
    applyPolygons();

    if (selected.value) {
        const anchor = children.value.find((c) => c.lat != null);

        globeInstance?.pointOfView(
            anchor
                ? { lat: anchor.lat, lng: anchor.lng, altitude: 1.6 }
                : { altitude: 1.6 },
            800,
        );
    }
}

async function selectCountry(country: Country) {
    // 點已經選取的同一國＝下鑽的快捷（面板上那顆按鈕才是正式入口）
    if (selected.value?.qid === country.qid) {
        if (!drilledQid.value) {
            void drillIn(country);
        }

        return;
    }

    // 換國家：先把上一國浮起來的板塊收掉
    if (drilledQid.value) {
        closeFlatNow();
        regionFeatures = [];
        drilledQid.value = null;
        activeRegionQid.value = null;
        applyPolygons();
    }

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
    closeFlatNow();
    selected.value = null;
    children.value = [];
    loadError.value = '';
    regionFeatures = [];
    drilledQid.value = null;
    activeRegionQid.value = null;
    tweenOffset(0);

    if (globeInstance) {
        globeInstance.controls().autoRotate = true;
        applyPolygons();
        refreshStyles();
        globeInstance.pointOfView({ altitude: 2.4 }, 800);
    }
}

/** Esc 一次退一層：2D → 行政區 → 國家 → 世界 */
function onKeydown(event: KeyboardEvent) {
    if (event.key !== 'Escape') {
        return;
    }

    if (isFlat.value) {
        unflatten();
    } else if (drilledQid.value) {
        drillOut();
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
    // 側牆帶透明度，讓下面的地球還透得出來，不然浮起的板塊會像一塊不透光的積木
    const regionSide = withAlpha(primary, 0.4);

    globeInstance = new Globe(containerEl.value)
        .backgroundImageUrl('/images/globe/night-sky.jpg')
        // 夜間版：這顆球背後要壓文字跟卡片，白天版太亮會跟內容搶對比
        .globeImageUrl('/images/globe/earth-night.jpg')
        .showAtmosphere(true)
        .atmosphereColor(primary)
        .atmosphereAltitude(0.16)
        // 行政區浮到國家層上方。altitude 在 three-globe 是 `scale = 1 + alt` 的
        // tween，不重建幾何，所以「升起來」這個動畫本身是免費的。
        .polygonAltitude((feat: any) =>
            feat.__region ? regionAltitude : 0.006,
        )
        .polygonCapMaterial((feat: any) => {
            if (feat.__region) {
                return feat.id === activeRegionQid.value
                    ? regionActiveCapMaterial
                    : regionCapMaterial;
            }

            return isSelectedPolygon(feat)
                ? selectedCapMaterial
                : idleCapMaterial;
        })
        // 國家層側牆設成 falsy 讓 three-globe 連幾何都不建（includeSides=false）。
        // 它原本就是全透明看不見的，卻仍然是三角形、還多佔一份材質。
        //
        // 浮起來的行政區反過來需要側牆——那圈牆就是「浮起」的視覺本體。
        // ⚠️ 側牆的有無會讓 ConicPolygonGeometry 重建，所以這個值只能依 feature
        // 種類決定，不能拿來做 hover 之類會反覆切換的效果。
        .polygonSideColor((feat: any) => (feat.__region ? regionSide : false))
        .polygonStrokeColor((feat: any) => {
            if (feat.__region) {
                return feat.id === activeRegionQid.value ? '#ffffff' : primary;
            }

            return isSelectedPolygon(feat) ? '#ffffff' : primary;
        })
        .polygonLabel((feat: any) => {
            if (feat.__region) {
                return regionName(feat);
            }

            const c = byIsoNumeric.value.get(polygonIso(feat));

            return c ? `${c.label}（${c.child_count} 個一級行政區）` : '';
        })
        .onPolygonClick((feat: any) => {
            if (feat.__region) {
                activeRegionQid.value = feat.id ?? null;
                refreshStyles();

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
    applyPolygons();
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
    regionFeatures = [];
    idleCapMaterial.dispose();
    selectedCapMaterial.dispose();
    regionCapMaterial.dispose();
    regionActiveCapMaterial.dispose();
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
                    <!-- 退出是逐層的：2D → 行政區 → 世界（Esc 也是同一條路） -->
                    <button
                        type="button"
                        class="binary-ghost-button mb-4 text-xs"
                        @click="
                            isFlat
                                ? unflatten()
                                : drilledQid
                                  ? drillOut()
                                  : backToWorld()
                        "
                    >
                        {{
                            isFlat
                                ? '← 回到地球'
                                : drilledQid
                                  ? '← 收起行政區'
                                  : '← 回到世界'
                        }}
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

                        <!-- 下鑽的正式入口。再點一次地球上同一國也會下鑽，但那是隱藏
                             的快捷鍵，不能當唯一入口——使用者不會知道要點第二次。 -->
                        <!-- binary-button 是 w-full 的，放在這一列會把標籤擠到換行，
                             這裡要的是行內動作，所以走 ghost 版再補一圈主色邊框 -->
                        <button
                            v-if="canDrill && !drilledQid"
                            type="button"
                            class="binary-ghost-button ml-auto shrink-0 border border-[var(--binary-primary)]/50 py-1 disabled:opacity-50"
                            :disabled="isDrilling"
                            @click="selected && drillIn(selected)"
                        >
                            {{ isDrilling ? '載入中…' : '展開邊界' }}
                        </button>
                        <!-- 下鑽後才給攤平入口：2D 層畫的就是浮起中的那批板塊 -->
                        <button
                            v-else-if="drilledQid && !isFlat"
                            type="button"
                            class="binary-ghost-button ml-auto shrink-0 border border-[var(--binary-primary)]/50 py-1"
                            @click="flatten"
                        >
                            攤平
                        </button>
                        <span
                            v-else-if="isFlat"
                            class="ml-auto truncate text-[10px] text-[var(--binary-outline)]"
                        >
                            {{ activeRegionQid ? regionLabel : '點區塊看名稱' }}
                        </span>
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

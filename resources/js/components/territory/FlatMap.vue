<script setup lang="ts">
/**
 * 行政區的 2D 攤平層（canvas）。
 *
 * ## 為什麼轉場不會「跳」
 *
 * 進場的第 0 幀，每個頂點的位置是父層用 **three.js 自己的相機**算出來的螢幕座標
 * （`Vector3.project(camera)`，見 Territory.vue 的 projectFromGlobe）。那就是地球
 * 當下實際畫在畫面上的位置，所以 t=0 時這層跟 WebGL 畫面在數學上完全重合——
 * 不是「很接近」，是同一個值。之後每幀把頂點內插到平面投影的目標位置，就會看到
 * 地球上的那塊真的被攤開。
 *
 * 用 d3 的 geoOrthographic 去近似相機也可以，但那是把透視當成正射，跨度大的國家
 * （巴西、印度那種半徑 15° 的）邊緣會差到 8%，交接瞬間會看得出來抖一下。
 *
 * ## 為什麼整層都用 canvas 而不是 SVG
 *
 * morph 期間每幀都要重算所有頂點（法國第一層有 15,040 個）。SVG 等於每幀重組
 * 一百多條 path 的 `d` 字串再交給瀏覽器重新解析，太貴；canvas 只是照著描一遍。
 * 靜態之後也留在 canvas：hover/點擊用 `isPointInPath` 打 Path2D 就好，比維持
 * 一百多個 DOM node 還輕，也省得為了轉場和靜態各寫一套。
 */
import { geoBounds, geoConicConformal, geoMercator } from 'd3';
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { themeColor, withAlpha } from '../../lib/theme-color';

const props = defineProps<{
    /** 要攤平的 feature（第一層行政區） */
    features: any[];
    /**
     * 交接當下「這個經緯度畫在螢幕的哪裡」，由父層用地球的相機算。
     * 回 null 代表這個點當下不在畫面上（轉到球背面），該環會被略過。
     */
    project: (lng: number, lat: number) => [number, number] | null;
    activeQid: string | null;
    labelOf: (feat: any) => string;
    /** 用來上色的量值（目前是人口），null 代表沒資料 */
    valueOf: (feat: any) => number | null;
    /** 資料面板蓋住的區域，地圖要避開（桌機面板在左、手機在下） */
    insetLeft?: number;
    insetBottom?: number;
}>();

const emit = defineEmits<{
    select: [qid: string];
    /** 算完要畫哪些之後回報，讓父層能說明有幾個海外屬地沒畫 */
    ready: [{ shown: number; dropped: number }];
    /** morph 播完（父層可以在這時把地球暫停） */
    done: [];
    /** 退場動畫播完，父層可以卸載這個元件了 */
    exited: [];
}>();

const canvasEl = ref<HTMLCanvasElement | null>(null);

interface Ring {
    /** 交接幀的螢幕座標，[x0,y0,x1,y1,...] */
    start: Float32Array;
    /** 攤平後的目標螢幕座標，長度與 start 相同 */
    end: Float32Array;
}

interface Shape {
    qid: string;
    label: string;
    value: number | null;
    rings: Ring[];
    /** 靜態之後拿來打 hit test 的路徑（座標是 CSS px） */
    path: Path2D | null;
    /** 最大一環的中心與寬度，決定標籤畫不畫得下 */
    labelAt: [number, number];
    labelRoom: number;
}

let shapes: Shape[] = [];
let ctx: CanvasRenderingContext2D | null = null;
let raf = 0;
let progress = 0;
let maxValue = 1;
/** 因為離本土太遠而沒畫的 feature 數（海外屬地），進場時回報給父層 */
let droppedCount = 0;
const hoverQid = ref<string | null>(null);

/** 畫布的 CSS 尺寸（不含 devicePixelRatio） */
let cssWidth = 0;
let cssHeight = 0;

const palette = {
    primary: '#6bdc9f',
    text: '#e8f5ee',
    outline: '#7a8c82',
};

function ringsOf(feature: any): number[][][] {
    const geometry = feature.geometry;

    return geometry.type === 'MultiPolygon'
        ? geometry.coordinates.flat()
        : geometry.coordinates;
}

/** 鞋帶公式（度²）。只拿來比大小，不需要是真實球面面積。 */
function ringArea(ring: number[][]): number {
    let total = 0;

    for (let i = 1; i < ring.length; i++) {
        total += ring[i - 1][0] * ring[i][1] - ring[i][0] * ring[i - 1][1];
    }

    return Math.abs(total) / 2;
}

function ringCenter(ring: number[][]): [number, number] {
    let minLng = Infinity;
    let maxLng = -Infinity;
    let minLat = Infinity;
    let maxLat = -Infinity;

    for (const [lng, lat] of ring) {
        minLng = Math.min(minLng, lng);
        maxLng = Math.max(maxLng, lng);
        minLat = Math.min(minLat, lat);
        maxLat = Math.max(maxLat, lat);
    }

    return [(minLng + maxLng) / 2, (minLat + maxLat) / 2];
}

/**
 * 只留本土那一團。
 *
 * 法國的第一層含法屬圭亞那（南美）和留尼旺（印度洋），整包丟給 `fitExtent` 會
 * 框出跨越 100 度經度的範圍，本土就被縮成畫面上的一小撮——實測就是這樣壞的。
 * 作法是先找全國最大的一環當錨點，再留下中心距離錨點 25 度以內的 feature。
 * 被排除的只是不畫，資料面板那邊照樣列得到。
 */
function selectMainland(features: any[]): any[] {
    let anchor: [number, number] | null = null;
    let anchorArea = -1;
    const centers = new Map<any, [number, number]>();

    for (const feature of features) {
        let best: number[][] | null = null;
        let bestArea = -1;

        for (const ring of ringsOf(feature)) {
            const area = ringArea(ring);

            if (area > bestArea) {
                bestArea = area;
                best = ring;
            }
        }

        if (!best) {
            continue;
        }

        const center = ringCenter(best);

        centers.set(feature, center);

        if (bestArea > anchorArea) {
            anchorArea = bestArea;
            anchor = center;
        }
    }

    if (!anchor) {
        return features;
    }

    const [anchorLng, anchorLat] = anchor;

    return features.filter((feature) => {
        const center = centers.get(feature);

        if (!center) {
            return false;
        }

        // 經度差要乘 cos(lat) 才是實際跨幅，不然高緯度會誤判成很遠
        const dLng =
            (center[0] - anchorLng) * Math.cos((anchorLat * Math.PI) / 180);
        const dLat = center[1] - anchorLat;

        return Math.hypot(dLng, dLat) <= 25;
    });
}

/**
 * 挑平面投影。
 *
 * 圓錐投影（conic conformal）對中高緯度、東西向寬的國家最好看，但它在赤道附近會
 * 退化（兩條標準緯線幾乎對稱時常數會爆掉），所以低緯度國家改用 mercator——
 * 單一國家的範圍內 mercator 的變形看不出來，而且它永遠不會壞。
 */
function pickProjection(features: any[]) {
    const collection = { type: 'FeatureCollection', features } as any;
    const [[west, south], [east, north]] = geoBounds(collection);
    const centerLat = (south + north) / 2;
    const centerLng = (west + east) / 2;

    const projection =
        Math.abs(centerLat) < 20
            ? geoMercator().rotate([-centerLng, 0])
            : geoConicConformal()
                  .rotate([-centerLng, 0])
                  // 標準緯線取上下各 1/6 處，是圓錐投影的慣用配方
                  .parallels([
                      south + (north - south) / 6,
                      north - (north - south) / 6,
                  ]);

    // 地圖只鋪在面板沒蓋到的地方。fitExtent 會自動算 scale 與 translate，
    // 所以這裡只要把「可用的矩形」講對，剩下的它會處理。
    const pad = Math.min(cssWidth, cssHeight) * 0.06;
    const left = (props.insetLeft ?? 0) + pad;
    const bottom = cssHeight - (props.insetBottom ?? 0) - pad;

    projection.fitExtent(
        [
            [left, pad],
            [cssWidth - pad, bottom],
        ],
        collection,
    );

    return projection;
}

function buildShapes() {
    const mainland = selectMainland(props.features);
    const projection = pickProjection(mainland);

    shapes = [];
    maxValue = 1;
    droppedCount = props.features.length - mainland.length;

    for (const feature of mainland) {
        const rings: Ring[] = [];
        let widest: { center: [number, number]; room: number } | null = null;

        for (const ring of ringsOf(feature)) {
            const start: number[] = [];
            const end: number[] = [];
            let minX = Infinity;
            let maxX = -Infinity;
            let sumX = 0;
            let sumY = 0;

            for (const [lng, lat] of ring) {
                const from = props.project(lng, lat);
                const to = projection([lng, lat]);

                // 任一端算不出來就整環放棄：補插一個假點會讓形狀扭曲，
                // 寧可少畫一個小島也不要畫出一條穿過畫面的線
                if (!from || !to) {
                    start.length = 0;

                    break;
                }

                start.push(from[0], from[1]);
                end.push(to[0], to[1]);
                minX = Math.min(minX, to[0]);
                maxX = Math.max(maxX, to[0]);
                sumX += to[0];
                sumY += to[1];
            }

            if (start.length < 6) {
                continue;
            }

            rings.push({
                start: new Float32Array(start),
                end: new Float32Array(end),
            });

            const room = maxX - minX;

            if (!widest || room > widest.room) {
                widest = {
                    center: [sumX / (end.length / 2), sumY / (end.length / 2)],
                    room,
                };
            }
        }

        if (!rings.length) {
            continue;
        }

        const value = props.valueOf(feature);

        maxValue = Math.max(maxValue, value ?? 0);

        shapes.push({
            qid: String(feature.id ?? ''),
            label: props.labelOf(feature),
            value,
            rings,
            path: null,
            labelAt: widest!.center,
            labelRoom: widest!.room,
        });
    }
}

/** 人口愈多填色愈實。開根號是因為人口分佈太偏，線性的話只有第一名看得出來。 */
function fillFor(shape: Shape): string {
    const ratio = shape.value ? Math.sqrt(shape.value / maxValue) : 0;

    if (shape.qid === props.activeQid) {
        return withAlpha(palette.primary, 0.55);
    }

    if (shape.qid === hoverQid.value) {
        return withAlpha(palette.primary, 0.18 + ratio * 0.45);
    }

    return withAlpha(palette.primary, 0.07 + ratio * 0.42);
}

function easeInOutCubic(t: number): number {
    return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
}

function pathFor(shape: Shape, t: number, settled: boolean): Path2D {
    const path = new Path2D();

    for (const { start, end } of shape.rings) {
        for (let i = 0; i < start.length; i += 2) {
            const x = settled ? end[i] : start[i] + (end[i] - start[i]) * t;
            const y = settled
                ? end[i + 1]
                : start[i + 1] + (end[i + 1] - start[i + 1]) * t;

            if (i === 0) {
                path.moveTo(x, y);
            } else {
                path.lineTo(x, y);
            }
        }

        path.closePath();
    }

    return path;
}

function draw(t: number) {
    if (!ctx) {
        return;
    }

    ctx.clearRect(0, 0, cssWidth, cssHeight);
    ctx.lineJoin = 'round';

    const settled = t >= 1;

    for (const shape of shapes) {
        // 靜態之後形狀不會再動，路徑沿用快取的那份：hover 或選取只是換顏色，
        // 不該為了換個填色把一萬五千個頂點重描一遍。
        const path =
            settled && shape.path ? shape.path : pathFor(shape, t, settled);

        ctx.fillStyle = fillFor(shape);
        ctx.fill(path, 'evenodd');

        const isActive =
            shape.qid === props.activeQid || shape.qid === hoverQid.value;

        ctx.strokeStyle = isActive
            ? palette.text
            : withAlpha(palette.primary, 0.75);
        ctx.lineWidth = isActive ? 1.8 : 0.8;
        ctx.stroke(path);

        shape.path = settled ? path : null;
    }

    // 標籤只在靜態時畫：morph 途中位置一直在動，字會糊成一團
    if (!settled) {
        return;
    }

    // 選取的那塊再描一次。上面是照陣列順序畫的，晚畫的鄰居會蓋掉它的邊，
    // 少了半圈白線會讓人以為點下去沒反應。
    const active = shapes.find((s) => s.qid === props.activeQid);

    if (active?.path) {
        ctx.strokeStyle = palette.text;
        ctx.lineWidth = 1.8;
        ctx.stroke(active.path);
    }

    ctx.font =
        '11px ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';

    for (const shape of shapes) {
        if (!shape.label) {
            continue;
        }

        const width = ctx.measureText(shape.label).width;

        // 塞不下就不畫。硬畫會壓到隔壁區，2D 的可讀性優勢就白費了
        if (width > shape.labelRoom - 8) {
            continue;
        }

        const [x, y] = shape.labelAt;

        // 描一圈底色，免得字落在亮色塊上看不見
        ctx.lineWidth = 3;
        ctx.strokeStyle = withAlpha('#000000', 0.55);
        ctx.strokeText(shape.label, x, y);
        ctx.fillStyle =
            shape.qid === props.activeQid ? palette.text : palette.outline;
        ctx.fillText(shape.label, x, y);
    }
}

function animate(
    from: number,
    to: number,
    duration: number,
    done?: () => void,
) {
    cancelAnimationFrame(raf);

    const startedAt = performance.now();

    const step = (now: number) => {
        const linear = Math.min(1, (now - startedAt) / duration);

        progress = from + (to - from) * easeInOutCubic(linear);
        draw(progress);

        if (linear < 1) {
            raf = requestAnimationFrame(step);
        } else {
            done?.();
        }
    };

    raf = requestAnimationFrame(step);
}

function resizeCanvas() {
    const canvas = canvasEl.value;

    if (!canvas || !canvas.parentElement) {
        return;
    }

    const dpr = Math.min(2, window.devicePixelRatio || 1);

    cssWidth = canvas.parentElement.clientWidth;
    cssHeight = canvas.parentElement.clientHeight;
    canvas.width = Math.round(cssWidth * dpr);
    canvas.height = Math.round(cssHeight * dpr);
    canvas.style.width = `${cssWidth}px`;
    canvas.style.height = `${cssHeight}px`;

    ctx = canvas.getContext('2d');
    // 之後一律用 CSS px 思考；isPointInPath 也吃同一組座標
    ctx?.setTransform(dpr, 0, 0, dpr, 0, 0);
}

function onResize() {
    resizeCanvas();

    // 視窗變了就沒有「交接幀」可言了，重算目標位置並讓起點等於終點，
    // 這樣之後的重畫不會突然跳回舊的球面座標
    buildShapes();

    for (const shape of shapes) {
        for (const ring of shape.rings) {
            ring.start.set(ring.end);
        }
    }

    draw(1);
}

function hitTest(event: MouseEvent): string | null {
    const canvas = canvasEl.value;

    if (!canvas || !ctx || progress < 1) {
        return null;
    }

    const rect = canvas.getBoundingClientRect();
    const x = event.clientX - rect.left;
    const y = event.clientY - rect.top;

    // 由後往前找：後畫的疊在上面
    for (let i = shapes.length - 1; i >= 0; i--) {
        const path = shapes[i].path;

        if (path && ctx.isPointInPath(path, x, y, 'evenodd')) {
            return shapes[i].qid;
        }
    }

    return null;
}

function onMouseMove(event: MouseEvent) {
    const hit = hitTest(event);

    if (hit !== hoverQid.value) {
        hoverQid.value = hit;
        draw(progress);
    }
}

function onClick(event: MouseEvent) {
    const hit = hitTest(event);

    if (hit) {
        emit('select', hit);
    }
}

/**
 * 選取的區塊是父層的狀態，canvas 不會自己知道它變了。
 *
 * 沒有這個 watch 的話，點下去不會立刻重畫——要等下一次 hover 變動才順便畫出來，
 * 而點擊當下 hover 本來就已經是同一塊，於是看起來就是「切換有延遲」。
 */
watch(
    () => props.activeQid,
    () => draw(progress),
);

/** 退場：倒帶回球面位置，父層等 exited 再卸載。 */
function exit() {
    animate(progress, 0, 700, () => emit('exited'));
}

defineExpose({ exit });

onMounted(() => {
    palette.primary = themeColor('--binary-primary', palette.primary);
    palette.text = themeColor('--binary-text', palette.text);
    palette.outline = themeColor('--binary-outline', palette.outline);

    resizeCanvas();
    buildShapes();
    emit('ready', { shown: shapes.length, dropped: droppedCount });
    draw(0);
    animate(0, 1, 900, () => emit('done'));

    window.addEventListener('resize', onResize);
});

onUnmounted(() => {
    cancelAnimationFrame(raf);
    window.removeEventListener('resize', onResize);
    shapes = [];
});
</script>

<template>
    <canvas
        ref="canvasEl"
        class="absolute inset-0 h-full w-full"
        :class="hoverQid ? 'cursor-pointer' : 'cursor-default'"
        @mousemove="onMouseMove"
        @mouseleave="hoverQid = null"
        @click="onClick"
    />
</template>

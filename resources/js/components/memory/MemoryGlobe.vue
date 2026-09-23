<script setup lang="ts">
// 知識圖譜 host 3D 地球（globe.gl，底層 Three.js）。資料來自 geo 觀察：
// 每個 host 依經緯度落點，host 間的 relation 以大圓弧連線。
// 純渲染：points / relations 來自父層 props，編輯後重給即重畫；點 host pin → emit hostClick。
import { onMounted, onUnmounted, ref, watch } from 'vue';

interface GeoPoint {
    entity: string;
    content: string; // "lat,lng"
}
interface Relation {
    from: string;
    relation_type: string;
    to: string;
}
interface Pin {
    entity: string;
    lat: number;
    lng: number;
}
interface Arc {
    startLat: number;
    startLng: number;
    endLat: number;
    endLng: number;
}

const props = defineProps<{
    points: GeoPoint[];
    relations: Relation[];
}>();

const emit = defineEmits<{ hostClick: [entity: string] }>();

const containerEl = ref<HTMLDivElement | null>(null);

let Globe: any = null;
let globeInstance: any = null;
let ready = false;

// entity → {lat, lng}
function coords(): Record<string, { lat: number; lng: number }> {
    const map: Record<string, { lat: number; lng: number }> = {};

    for (const p of props.points) {
        const [lat, lng] = p.content.split(',').map((s) => Number(s.trim()));

        if (Number.isFinite(lat) && Number.isFinite(lng)) {
            map[p.entity] = { lat, lng };
        }
    }

    return map;
}

function renderData() {
    if (!ready || !globeInstance) {
        return;
    }

    const map = coords();

    const pins: Pin[] = Object.entries(map).map(([entity, c]) => ({
        entity,
        lat: c.lat,
        lng: c.lng,
    }));

    // arcs：只連兩端都有 geo 的 relation
    const arcs: Arc[] = props.relations
        .filter((r) => map[r.from] && map[r.to])
        .map((r) => ({
            startLat: map[r.from].lat,
            startLng: map[r.from].lng,
            endLat: map[r.to].lat,
            endLng: map[r.to].lng,
        }));

    globeInstance.pointsData(pins).arcsData(arcs);
}

async function initGlobe() {
    if (!containerEl.value) {
        return;
    }

    if (!Globe) {
        Globe = (await import('globe.gl')).default;
    }

    // 套件下載期間（await 中）元件可能已經被卸載，containerEl 會變成 null；
    // 不重新檢查的話 new Globe(null) 會噴錯，且建出來的 instance 因為錯過
    // onUnmounted 時機、永遠不會被 _destructor() 清掉（memory leak）。
    if (!containerEl.value) {
        return;
    }

    globeInstance = new Globe(containerEl.value)
        .backgroundImageUrl('/images/globe/night-sky.jpg')
        // Memory 這邊想要白天地球（跟 Airports 的夜間版不同）：藍色海洋 + 綠棕色陸地，
        // 真實衛星色彩，跟 arc/pin 的綠色主題色搭起來也比較活潑。
        .globeImageUrl('/images/globe/earth-day.jpg')
        .bumpImageUrl('/images/globe/earth-topology.png')
        .showAtmosphere(true)
        .atmosphereColor('#2ca46d')
        .atmosphereAltitude(0.15)
        // 明顯的珊瑚紅：白天地球底色是藍海洋+綠棕陸地，淺綠 pin 疊在綠色陸地上會糊掉，
        // 換成跟藍/綠/棕都有反差的顏色才看得清楚。
        .pointColor(() => '#ff5566')
        .pointAltitude(0.01)
        .pointRadius(0.35)
        .pointLabel((d: Pin) => d.entity)
        .onPointClick((d: Pin) => emit('hostClick', d.entity))
        .arcColor(() => 'rgba(107,220,159,0.5)')
        .arcStroke(0.3)
        .pointOfView({ lat: 24, lng: 121, altitude: 2.2 }, 0);

    globeInstance
        .width(containerEl.value.clientWidth)
        .height(containerEl.value.clientHeight);

    // 不畫國界：換成 earth-day 真實衛星貼圖後，裝飾用的政治國界疊線反而顯得多餘/雜亂，
    // 拿掉，讓真實地形貼圖乾淨露出來。
    ready = true;
    renderData();
}

onMounted(initGlobe);

onUnmounted(() => {
    globeInstance?._destructor?.();
    globeInstance = null;
});

// props 變動（如編輯後重抓 geo）→ 重畫資料層
watch(() => [props.points, props.relations], renderData, { deep: true });
</script>

<template>
    <div
        class="relative h-full w-full overflow-hidden rounded-xl border border-[var(--binary-outline-variant)] bg-[#0d141d]"
    >
        <div ref="containerEl" class="h-full w-full" />
    </div>
</template>

<script setup lang="ts">
// 機場地球（globe.gl，底層 Three.js）。國界 polygon 可點擊：高亮 + 鏡頭飛過去 + 抓該國機場。
import * as THREE from 'three';
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { api } from '@/lib/routes';

interface AirportItem {
    name: string;
    location: { latitude: number | null; longitude: number | null };
}

const { t } = useI18n();

const containerEl = ref<HTMLDivElement | null>(null);
const selectedCountryCode = ref('');
const selectedCountryName = ref('');
const selectedNumericId = ref<string | null>(null);
const airportCount = ref(0);
const isLoading = ref(false);
const loadError = ref('');

let Globe: any = null;
let globeInstance: any = null;

/**
 * 所有 polygon 共用這兩份 cap 材質。
 *
 * three-globe 是逐「單一 polygon 塊」建物件的（MultiPolygon 會被拆開），這份國界拆完
 * 約 436 塊，預設每塊都會自己 new 一份材質。改成共用實例後材質降到 2 份，draw call
 * 之間不必再切換材質狀態。
 *
 * 未選取那份用 `colorWrite: false`：畫面上跟全透明一樣看不見，但它不是「透明物件」，
 * 所以不進透明佇列、不做混合、也不必每幀重新深度排序。
 * ⚠️ cap 不能省——點擊偵測就是對這片看不見的 cap 做 raycast，拿掉就點不到國家了。
 */
const idleCapMaterial = new THREE.MeshBasicMaterial({
    colorWrite: false,
    depthWrite: false,
});
const selectedCapMaterial = new THREE.MeshBasicMaterial({
    color: 0x00e5ff,
    transparent: true,
    opacity: 0.35,
    depthWrite: false,
});

const alpha2ToNumeric: Record<string, string> = {
    AF: '004',
    AX: '248',
    AL: '008',
    DZ: '012',
    AS: '016',
    AD: '020',
    AO: '024',
    AI: '660',
    AQ: '010',
    AG: '028',
    AR: '032',
    AM: '051',
    AW: '533',
    AU: '036',
    AT: '040',
    AZ: '031',
    BS: '044',
    BH: '048',
    BD: '050',
    BB: '052',
    BY: '112',
    BE: '056',
    BZ: '084',
    BJ: '204',
    BM: '060',
    BT: '064',
    BO: '068',
    BQ: '535',
    BA: '070',
    BW: '072',
    BV: '074',
    BR: '076',
    IO: '086',
    BN: '096',
    BG: '100',
    BF: '854',
    BI: '108',
    CV: '132',
    KH: '116',
    CM: '120',
    CA: '124',
    KY: '136',
    CF: '140',
    TD: '148',
    CL: '152',
    CN: '156',
    CX: '162',
    CC: '166',
    CO: '170',
    KM: '174',
    CG: '178',
    CD: '180',
    CK: '184',
    CR: '188',
    CI: '384',
    HR: '191',
    CU: '192',
    CW: '531',
    CY: '196',
    CZ: '203',
    DK: '208',
    DJ: '262',
    DM: '212',
    DO: '214',
    EC: '218',
    EG: '818',
    SV: '222',
    GQ: '226',
    ER: '232',
    EE: '233',
    SZ: '748',
    ET: '231',
    FK: '238',
    FO: '234',
    FJ: '242',
    FI: '246',
    FR: '250',
    GF: '254',
    PF: '258',
    TF: '260',
    GA: '266',
    GM: '270',
    GE: '268',
    DE: '276',
    GH: '288',
    GI: '292',
    GR: '300',
    GL: '304',
    GD: '308',
    GP: '312',
    GU: '316',
    GT: '320',
    GG: '831',
    GN: '324',
    GW: '624',
    GY: '328',
    HT: '332',
    HM: '334',
    VA: '336',
    HN: '340',
    HK: '344',
    HU: '348',
    IS: '352',
    IN: '356',
    ID: '360',
    IR: '364',
    IQ: '368',
    IE: '372',
    IM: '833',
    IL: '376',
    IT: '380',
    JM: '388',
    JP: '392',
    JE: '832',
    JO: '400',
    KZ: '398',
    KE: '404',
    KI: '296',
    KP: '408',
    KR: '410',
    KW: '414',
    KG: '417',
    LA: '418',
    LV: '428',
    LB: '422',
    LS: '426',
    LR: '430',
    LY: '434',
    LI: '438',
    LT: '440',
    LU: '442',
    MO: '446',
    MG: '450',
    MW: '454',
    MY: '458',
    MV: '462',
    ML: '466',
    MT: '470',
    MH: '584',
    MQ: '474',
    MR: '478',
    MU: '480',
    YT: '175',
    MX: '484',
    FM: '583',
    MD: '498',
    MC: '492',
    MN: '496',
    ME: '499',
    MS: '500',
    MA: '504',
    MZ: '508',
    MM: '104',
    NA: '516',
    NR: '520',
    NP: '524',
    NL: '528',
    NC: '540',
    NZ: '554',
    NI: '558',
    NE: '562',
    NG: '566',
    NU: '570',
    NF: '574',
    MK: '807',
    MP: '580',
    NO: '578',
    OM: '512',
    PK: '586',
    PW: '585',
    PS: '275',
    PA: '591',
    PG: '598',
    PY: '600',
    PE: '604',
    PH: '608',
    PN: '612',
    PL: '616',
    PT: '620',
    PR: '630',
    QA: '634',
    RE: '638',
    RO: '642',
    RU: '643',
    RW: '646',
    BL: '652',
    SH: '654',
    KN: '659',
    LC: '662',
    MF: '663',
    PM: '666',
    VC: '670',
    WS: '882',
    SM: '674',
    ST: '678',
    SA: '682',
    SN: '686',
    RS: '688',
    SC: '690',
    SL: '694',
    SG: '702',
    SX: '534',
    SK: '703',
    SI: '705',
    SB: '090',
    SO: '706',
    ZA: '710',
    GS: '239',
    SS: '728',
    ES: '724',
    LK: '144',
    SD: '729',
    SR: '740',
    SJ: '744',
    SE: '752',
    CH: '756',
    SY: '760',
    TW: '158',
    TJ: '762',
    TZ: '834',
    TH: '764',
    TL: '626',
    TG: '768',
    TK: '772',
    TO: '776',
    TT: '780',
    TN: '788',
    TR: '792',
    TM: '795',
    TC: '796',
    TV: '798',
    UG: '800',
    UA: '804',
    AE: '784',
    GB: '826',
    US: '840',
    UM: '581',
    UY: '858',
    UZ: '860',
    VU: '548',
    VE: '862',
    VN: '704',
    VG: '092',
    VI: '850',
    WF: '876',
    EH: '732',
    YE: '887',
    ZM: '894',
    ZW: '716',
    XK: '383',
};

const numericToAlpha2 = Object.entries(alpha2ToNumeric).reduce<
    Record<string, string>
>((acc, [alpha2, numeric]) => {
    acc[numeric] = alpha2;

    return acc;
}, {});

function renderPins(items: AirportItem[]) {
    const pins = items
        .filter(
            (a) => a.location.latitude != null && a.location.longitude != null,
        )
        .map((a) => ({
            lat: a.location.latitude as number,
            lng: a.location.longitude as number,
        }));

    globeInstance?.pointsData(pins);
}

async function searchCountryAirports(alpha2: string) {
    isLoading.value = true;
    loadError.value = '';
    airportCount.value = 0;

    try {
        const params = new URLSearchParams({
            country: alpha2,
            per_page: '1000',
        });
        const res = await fetch(`${api.airports.index()}?${params}`, {
            headers: { Accept: 'application/json' },
        });
        const json = await res.json();

        if (!res.ok) {
            throw new Error(json?.message || 'Search failed');
        }

        const rows = (json.data ?? []) as AirportItem[];
        airportCount.value = rows.length;
        renderPins(rows);
    } catch (error) {
        loadError.value =
            error instanceof Error ? error.message : 'Search failed';
        renderPins([]);
    } finally {
        isLoading.value = false;
    }
}

async function onCountryClick(
    feature: any,
    coords: { lat: number; lng: number },
) {
    const numeric = String(feature.id).padStart(3, '0');
    const alpha2 = numericToAlpha2[numeric];

    selectedNumericId.value = numeric;
    globeInstance?.pointOfView({ lat: coords.lat, lng: coords.lng }, 650);

    selectedCountryCode.value = alpha2 ?? '';
    selectedCountryName.value =
        feature?.properties?.name ?? alpha2 ?? `ID-${numeric}`;

    if (!alpha2) {
        loadError.value = 'Country code unavailable';
        airportCount.value = 0;
        renderPins([]);

        return;
    }

    await searchCountryAirports(alpha2);
}

// 3D 的 material 建立後不會重新求值（同 CodeGraph 3D 模式的既有模式），
// 選取變動時要重新塞一次 accessor 自己觸發重繪。
watch(selectedNumericId, () => {
    if (!globeInstance) {
        return;
    }

    globeInstance.polygonCapMaterial(globeInstance.polygonCapMaterial());
    globeInstance.polygonStrokeColor(globeInstance.polygonStrokeColor());
});

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
        // 用 NASA 夜間衛星圖（three-globe demo 素材），取代自訂純色 + bump 的組合——
        // 深藍海洋 + 城市燈光光點，海陸對比明顯，換過 earth-dark.jpg 才發現那張圖本身
        // 像素就幾乎全黑（不是燈光沒打夠），這張才是真的看得出細節的深色地球。
        .globeImageUrl('/images/globe/earth-night.jpg')
        .showAtmosphere(true)
        .atmosphereColor('#00daf3')
        .atmosphereAltitude(0.15)
        .polygonAltitude(0.006)
        .polygonCapMaterial((feat: any) =>
            String(feat.id).padStart(3, '0') === selectedNumericId.value
                ? selectedCapMaterial
                : idleCapMaterial,
        )
        // 側牆一定要 falsy 才不會建幾何：three-globe 的判斷是
        // `hasSide = !!(sideColor || sideMaterial)`，回 'rgba(0,0,0,0)' 沒用（那是真值），
        // 照樣會建出一圈看不見的三角形還多佔一份材質。
        .polygonSideColor(() => false)
        .polygonStrokeColor((feat: any) =>
            String(feat.id).padStart(3, '0') === selectedNumericId.value
                ? '#ffffff'
                : '#00daf3',
        )
        .onPolygonClick((feature: any, _event: MouseEvent, coords: any) => {
            void onCountryClick(feature, coords);
        })
        .pointColor(() => '#00e5ff')
        .pointAltitude(0.01)
        .pointRadius(0.25)
        // altitude 調低讓球體撐滿容器（原本 2.2 鏡頭拉太遠，球體只占畫面中間一小塊，
        // 四周留一大圈星空空白，不算「佈滿」）。
        .pointOfView({ lat: 22, lng: 0, altitude: 1.4 }, 0);

    resizeToContainer();

    // 自架的混合國界（110m 骨架 + 50m 獨有的 61 個小島），由
    // scripts/build-globe-geojson.py 產生。已經是 GeoJSON，不需要 topojson.feature()。
    //
    // ⚠️ 不要改成完整的 50m：瓶頸不是下載（本機實測 6 ms）也不是 JSON.parse（8 ms），
    // 而是 three-globe 逐塊建 ConicPolygonGeometry——50m 是 1,616 塊／99,539 頂點，
    // 實測是單一個 5.6 秒的長任務（headless CPU），期間整頁凍住。
    // 混合版是 436 塊／12,218 頂點，沒有這種巨型任務。
    const world = await fetch('/geo/countries-hybrid.json').then(
        (r) => r.json() as Promise<{ features: unknown[] }>,
    );

    globeInstance.polygonsData(world.features);
}

function resizeToContainer() {
    if (!containerEl.value || !globeInstance) {
        return;
    }

    globeInstance
        .width(containerEl.value.clientWidth)
        .height(containerEl.value.clientHeight);
}

onMounted(() => {
    void initGlobe();
    window.addEventListener('resize', resizeToContainer);
});

onUnmounted(() => {
    window.removeEventListener('resize', resizeToContainer);
    globeInstance?._destructor?.();
    globeInstance = null;
    idleCapMaterial.dispose();
    selectedCapMaterial.dispose();
});
</script>

<template>
    <!-- 地球（含星空背景）鋪滿整個 section 當背景層；標題/提示文字拿掉（globe 分頁按鈕本身
         已經說明這是什麼、互動也算直覺），只留「目前選取」小徽章浮在右上角，不擋畫面。 -->
    <section
        class="binary-card-raised relative isolate h-[420px] overflow-hidden p-4 md:h-[520px] md:p-6"
    >
        <div ref="containerEl" class="absolute inset-0" />

        <div
            class="absolute top-3 right-3 z-10 rounded-xl bg-[#0d141d]/50 p-2 text-right backdrop-blur-sm"
        >
            <p
                class="binary-label text-[10px] text-[var(--binary-outline)] uppercase"
            >
                {{ t('airports.globe.selected') }}
            </p>
            <p class="text-sm font-bold text-[var(--binary-primary)]">
                {{ selectedCountryCode || '--' }}
                <span
                    class="ml-1 text-xs font-normal text-[var(--binary-text-muted)]"
                    >{{ selectedCountryName }}</span
                >
            </p>
            <p class="text-[10px] text-[var(--binary-outline)]">
                {{
                    t('airports.globe.airport_count', {
                        count: airportCount.toLocaleString(),
                    })
                }}
            </p>
        </div>

        <div
            v-if="isLoading"
            class="absolute inset-x-0 top-3 z-10 text-center text-xs text-[var(--binary-primary)]"
        >
            {{ t('airports.globe.loading') }}
        </div>
        <div
            v-if="loadError"
            class="absolute inset-x-0 bottom-3 z-10 text-center text-xs text-red-300"
        >
            {{ loadError }}
        </div>
    </section>
</template>

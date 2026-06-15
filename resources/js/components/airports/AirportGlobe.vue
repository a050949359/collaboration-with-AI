<script setup lang="ts">
import {
    drag,
    geoDistance,
    geoCentroid,
    geoGraticule,
    geoOrthographic,
    geoPath,
    interpolate,
    json,
    select,
    transition,
    zoom,
    zoomIdentity,
} from 'd3';
import type { GeoPath, GeoProjection, Selection } from 'd3';
import * as topojson from 'topojson-client';
import type { Topology } from 'topojson-specification';
import { onMounted, ref } from 'vue';
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
const airportCount = ref(0);
const isLoading = ref(false);
const loadError = ref('');

const W = 780;
const H = 520;
const INITIAL_SCALE = 240;

let projection: GeoProjection;
let path: GeoPath;
let svgEl: Selection<SVGSVGElement, unknown, null, undefined>;
let gLand: Selection<SVGGElement, unknown, null, undefined>;
let gPins: Selection<SVGGElement, unknown, null, undefined>;
let countries: any[] = [];
let renderRequested = false;

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
    svgEl.select<SVGPathElement>('.globe-sphere').attr('d', path as any);
    svgEl
        .select<SVGPathElement>('.graticule')
        .attr('d', path(geoGraticule()()) as any);
    gLand.selectAll<SVGPathElement, any>('path').attr('d', path as any);

    const center = projection.invert!([W / 2, H / 2])!;
    gPins
        .selectAll<SVGCircleElement, [number, number]>('circle')
        .attr('cx', (d) => projection(d)![0])
        .attr('cy', (d) => projection(d)![1])
        .attr('visibility', (d) =>
            geoDistance(d, center) > 1.57 ? 'hidden' : 'visible',
        );
}

function renderPins(items: AirportItem[]) {
    gPins.selectAll('*').remove();
    items.forEach((airport) => {
        const lat = airport.location.latitude;
        const lon = airport.location.longitude;

        if (lat == null || lon == null) {
            return;
        }

        gPins
            .append('circle')
            .datum([lon, lat] as [number, number])
            .attr('r', 3.5)
            .attr('fill', '#00e5ff')
            .attr('stroke', '#001f24')
            .attr('stroke-width', 0.8)
            .attr('cx', (d) => projection(d)![0])
            .attr('cy', (d) => projection(d)![1]);
    });
    requestRender();
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

function highlightCountryById(countryId: string) {
    gLand
        .selectAll<SVGPathElement, any>('path')
        .attr('fill', (d) =>
            String(d.id).padStart(3, '0') === countryId
                ? 'rgba(0,229,255,0.35)'
                : 'rgba(0,79,88,0.4)',
        )
        .attr('stroke', (d) =>
            String(d.id).padStart(3, '0') === countryId ? '#ffffff' : '#00daf3',
        )
        .attr('stroke-width', (d) =>
            String(d.id).padStart(3, '0') === countryId ? 1.4 : 0.5,
        );
}

function rotateToCountry(feature: any) {
    const centroid = geoCentroid(feature);
    const r0 = projection.rotate();
    const r1: [number, number] = [-centroid[0], -centroid[1]];

    transition()
        .duration(650)
        .tween('rotate', () => {
            const ir = interpolate(r0, r1);

            return (t: number) => {
                projection.rotate(ir(t));
                requestRender();
            };
        });
}

async function onCountryClick(feature: any) {
    const numeric = String(feature.id).padStart(3, '0');
    const alpha2 = numericToAlpha2[numeric];

    highlightCountryById(numeric);
    rotateToCountry(feature);

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
        .rotate([0, -22])
        .clipAngle(90);

    path = geoPath().projection(projection);

    svgEl
        .append('path')
        .datum({ type: 'Sphere' } as any)
        .attr('class', 'globe-sphere')
        .attr('fill', '#0d141d')
        .attr('stroke', '#00daf3')
        .attr('stroke-width', 0.4)
        .attr('d', path as any);

    svgEl
        .append('path')
        .datum(geoGraticule()())
        .attr('class', 'graticule')
        .attr('fill', 'none')
        .attr('stroke', 'rgba(0,229,255,0.08)')
        .attr('stroke-width', 0.5)
        .attr('d', path as any);

    gLand = svgEl.append('g');
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

    countries = (
        topojson.feature(world, (world.objects as any).countries) as any
    ).features;

    gLand
        .selectAll<SVGPathElement, any>('path')
        .data(countries)
        .enter()
        .append('path')
        .attr('fill', 'rgba(0,79,88,0.4)')
        .attr('stroke', '#00daf3')
        .attr('stroke-width', 0.5)
        .attr('d', path as any)
        .style('cursor', 'pointer')
        .on('click', (_, feature) => {
            void onCountryClick(feature);
        });

    requestRender();
}

onMounted(() => {
    void initGlobe();
});
</script>

<template>
    <section class="binary-card-raised p-4 md:p-6">
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h3
                    class="binary-label text-xs font-bold text-[var(--binary-outline)] uppercase"
                >
                    &gt; {{ t('airports.globe.title') }}
                </h3>
                <p class="mt-1 text-xs text-[var(--binary-text-muted)]">
                    {{ t('airports.globe.hint') }}
                </p>
            </div>
            <div class="text-right">
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
        </div>

        <div
            class="relative overflow-hidden rounded-xl border border-[var(--binary-outline)]/20 bg-[#0d141d]"
        >
            <div ref="containerEl" class="h-[360px] w-full md:h-[460px]" />
            <div
                v-if="isLoading"
                class="absolute inset-x-0 top-3 text-center text-xs text-[var(--binary-primary)]"
            >
                {{ t('airports.globe.loading') }}
            </div>
            <div
                v-if="loadError"
                class="absolute inset-x-0 bottom-3 text-center text-xs text-red-300"
            >
                {{ loadError }}
            </div>
        </div>
    </section>
</template>

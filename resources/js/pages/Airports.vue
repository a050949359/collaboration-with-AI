<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { ref, reactive, onMounted, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AirportGlobe from '../components/airports/AirportGlobe.vue';
import AppLayout from '../layouts/AppLayout.vue';

const { t } = useI18n();
const page = usePage<{ airportTypes: string[] }>();

// ── Types ─────────────────────────────────────────────────
interface AirportLocation {
    latitude: number | null;
    longitude: number | null;
    elevation_ft: number | null;
    municipality: string | null;
    region: string | null;
    country: string | null;
    continent: string | null;
}

interface Airport {
    id: number;
    ident: string;
    type: string;
    name: string;
    location: AirportLocation;
    codes: { iata: string | null; icao: string | null; gps: string | null };
    scheduled_service: boolean;
    links: { home: string | null; wikipedia: string | null };
}

interface Meta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Stats {
    total: number;
    scheduled_service: number;
    by_type: Record<string, number>;
    by_continent: Record<string, number>;
    top_countries: Record<string, number>;
}

// ── State ─────────────────────────────────────────────────
const filters = reactive({
    search: '',
    types: [] as string[],
    continents: [] as string[],
    country: '',
    scheduled: '',
    per_page: 20,
});

const airports = ref<Airport[]>([]);
const meta = ref<Meta | null>(null);
const stats = ref<Stats | null>(null);

const isLoadingList = ref(false);
const isLoadingStats = ref(false);
const listError = ref('');
const statsError = ref('');

const activeTab = ref<'search' | 'stats' | 'globe'>('search');

const typeKeys = computed(() => page.props.airportTypes);

const continentKeys = ['AF', 'AN', 'AS', 'EU', 'NA', 'OC', 'SA'] as const;

// ── Airport search ─────────────────────────────────────────
async function fetchAirports(page = 1) {
    isLoadingList.value = true;
    listError.value = '';

    const params = new URLSearchParams();
    params.set('page', String(page));
    params.set('per_page', String(filters.per_page));

    if (filters.search) {
        params.set('search', filters.search);
    }

    if (filters.country) {
        params.set('country', filters.country.toUpperCase());
    }

    if (filters.scheduled) {
        params.set('scheduled', filters.scheduled);
    }

    filters.types.forEach((t) => params.append('type[]', t));
    filters.continents.forEach((c) => params.append('continent[]', c));

    try {
        const res = await fetch(`/api/v1/airports?${params}`, {
            credentials: 'include',
            headers: { Accept: 'application/json' },
        });
        const json = await res.json();

        if (!res.ok) {
            throw new Error(json.message || t('airports.query_failed'));
        }

        airports.value = json.data;
        meta.value = json.meta;
    } catch (e: unknown) {
        listError.value =
            e instanceof Error ? e.message : t('common.error_connection');
    } finally {
        isLoadingList.value = false;
    }
}

// ── Stats ──────────────────────────────────────────────────
async function fetchStats() {
    if (stats.value) {
        return;
    }

    isLoadingStats.value = true;
    statsError.value = '';

    try {
        const res = await fetch('/api/v1/airports/stats', {
            credentials: 'include',
            headers: { Accept: 'application/json' },
        });
        const json = await res.json();

        if (!res.ok) {
            throw new Error(json.message || t('airports.stats.load_failed'));
        }

        stats.value = json.data;
    } catch (e: unknown) {
        statsError.value =
            e instanceof Error ? e.message : t('common.error_connection');
    } finally {
        isLoadingStats.value = false;
    }
}

function switchTab(tab: 'search' | 'stats' | 'globe') {
    activeTab.value = tab;

    if (tab === 'stats') {
        fetchStats();
    }
}

// ── Computed ───────────────────────────────────────────────
const topTypeEntries = computed(() =>
    stats.value ? Object.entries(stats.value.by_type).slice(0, 6) : [],
);
const topContinentEntries = computed(() =>
    stats.value ? Object.entries(stats.value.by_continent) : [],
);
const topCountryEntries = computed(() =>
    stats.value ? Object.entries(stats.value.top_countries).slice(0, 10) : [],
);
const maxTypeCount = computed(() =>
    topTypeEntries.value.reduce((m, [, v]) => Math.max(m, v), 1),
);

onMounted(() => fetchAirports());
</script>

<template>
    <Head :title="t('airports.title')" />

    <AppLayout>
        <main class="pb-24">
            <div class="mx-auto max-w-screen-2xl px-6 md:px-8">
                <!-- Header -->
                <div class="mb-10 pt-8">
                    <span
                        class="binary-label mb-2 block text-xs font-bold text-[var(--binary-primary)] uppercase"
                        >&gt; airport_database</span
                    >
                    <h1
                        class="binary-display text-5xl font-black tracking-tight uppercase md:text-7xl"
                    >
                        {{ t('airports.title').toUpperCase() }}
                    </h1>
                    <p class="mt-3 text-sm text-[var(--binary-text-muted)]">
                        {{ t('airports.subtitle') }}
                    </p>
                    <p class="mt-2 text-xs text-[var(--binary-outline)]">
                        {{ t('airports.source_label') }}
                        <a
                            href="https://ourairports.com/"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="underline underline-offset-2 hover:text-[var(--binary-primary)]"
                            >{{ t('airports.source_link') }}</a
                        >
                        <span class="mx-1">/</span>
                        <a
                            href="https://www.wikidata.org/"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="underline underline-offset-2 hover:text-[var(--binary-primary)]"
                            >{{ t('airports.source_link_wiki') }}</a
                        >
                    </p>
                </div>

                <!-- Tabs -->
                <div class="mb-8 flex gap-2">
                    <button
                        class="binary-label rounded-lg px-5 py-2 text-xs font-bold uppercase transition"
                        :class="
                            activeTab === 'search'
                                ? 'bg-[var(--binary-primary)] text-[var(--binary-on-primary-container)]'
                                : 'bg-[var(--binary-surface-container)] text-[var(--binary-outline)] hover:text-[var(--binary-text)]'
                        "
                        @click="switchTab('search')"
                    >
                        {{ t('airports.tab_search') }}
                    </button>
                    <button
                        class="binary-label rounded-lg px-5 py-2 text-xs font-bold uppercase transition"
                        :class="
                            activeTab === 'stats'
                                ? 'bg-[var(--binary-primary)] text-[var(--binary-on-primary-container)]'
                                : 'bg-[var(--binary-surface-container)] text-[var(--binary-outline)] hover:text-[var(--binary-text)]'
                        "
                        @click="switchTab('stats')"
                    >
                        {{ t('airports.tab_stats') }}
                    </button>
                    <button
                        class="binary-label flex items-center gap-1 rounded-lg px-4 py-2 text-xs font-bold uppercase transition"
                        :class="
                            activeTab === 'globe'
                                ? 'bg-[var(--binary-primary)] text-[var(--binary-on-primary-container)]'
                                : 'bg-[var(--binary-surface-container)] text-[var(--binary-outline)] hover:text-[var(--binary-text)]'
                        "
                        @click="switchTab('globe')"
                    >
                        <svg
                            class="h-3.5 w-3.5"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                            viewBox="0 0 24 24"
                        >
                            <circle cx="12" cy="12" r="10" />
                            <path
                                d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"
                            />
                        </svg>
                        {{ t('airports.tab_globe') }}
                    </button>
                </div>

                <!-- ── Search Tab ── -->
                <template v-if="activeTab === 'search'">
                    <!-- Checkbox Filters (auto-apply) -->
                    <div class="mb-4 space-y-3">
                        <!-- Type -->
                        <div
                            class="flex flex-wrap items-center gap-x-4 gap-y-2"
                        >
                            <span
                                class="binary-label w-12 shrink-0 text-[10px] text-[var(--binary-outline)] uppercase"
                                >{{ t('airports.col_type') }}</span
                            >
                            <label
                                v-for="key in typeKeys"
                                :key="key"
                                class="binary-label flex cursor-pointer items-center gap-1.5 text-xs"
                            >
                                <input
                                    v-model="filters.types"
                                    type="checkbox"
                                    :value="key"
                                    class="accent-[var(--binary-primary)]"
                                    @change="fetchAirports(1)"
                                />
                                <span
                                    :class="
                                        filters.types.includes(key)
                                            ? 'text-[var(--binary-primary)]'
                                            : 'text-[var(--binary-outline)]'
                                    "
                                >
                                    {{ t(`airports.types.${key}`) }}
                                </span>
                            </label>
                        </div>
                        <!-- Continent -->
                        <div
                            class="flex flex-wrap items-center gap-x-4 gap-y-2"
                        >
                            <span
                                class="binary-label w-12 shrink-0 text-[10px] text-[var(--binary-outline)] uppercase"
                                >{{ t('airports.col_continent') }}</span
                            >
                            <label
                                v-for="key in continentKeys"
                                :key="key"
                                class="binary-label flex cursor-pointer items-center gap-1.5 text-xs"
                            >
                                <input
                                    v-model="filters.continents"
                                    type="checkbox"
                                    :value="key"
                                    class="accent-[var(--binary-primary)]"
                                    @change="fetchAirports(1)"
                                />
                                <span
                                    :class="
                                        filters.continents.includes(key)
                                            ? 'text-[var(--binary-primary)]'
                                            : 'text-[var(--binary-outline)]'
                                    "
                                >
                                    {{ t(`airports.continents.${key}`) }}
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Search Bar (manual submit) -->
                    <form
                        class="mb-6 flex items-center gap-2"
                        @submit.prevent="fetchAirports(1)"
                    >
                        <input
                            v-model="filters.search"
                            class="binary-input flex-[3]"
                            :placeholder="t('airports.search_placeholder')"
                            type="text"
                        />
                        <input
                            v-model="filters.country"
                            class="binary-input flex-[3]"
                            :placeholder="t('airports.country_placeholder')"
                            maxlength="2"
                            type="text"
                        />
                        <button
                            class="binary-button ml-4 flex-[1] text-xs whitespace-nowrap"
                            type="submit"
                        >
                            {{ t('airports.tab_search') }} →
                        </button>
                    </form>

                    <!-- Error -->
                    <p
                        v-if="listError"
                        class="mb-4 rounded-lg border border-red-400/20 bg-red-950/20 px-4 py-3 text-sm text-red-300"
                    >
                        {{ listError }}
                    </p>

                    <!-- Loading -->
                    <div
                        v-if="isLoadingList"
                        class="py-16 text-center text-sm text-[var(--binary-text-muted)]"
                    >
                        {{ t('common.loading') }}
                    </div>

                    <!-- Empty -->
                    <div
                        v-else-if="!airports.length"
                        class="py-16 text-center text-sm text-[var(--binary-text-muted)]"
                    >
                        {{ t('common.no_data') }}
                    </div>

                    <!-- Table -->
                    <template v-else>
                        <div
                            class="overflow-x-auto rounded-2xl border border-[var(--binary-outline)]/10"
                        >
                            <table class="binary-label w-full text-xs">
                                <thead class="bg-[var(--binary-surface-high)]">
                                    <tr>
                                        <th
                                            class="px-4 py-3 text-left text-[var(--binary-outline)] uppercase"
                                        >
                                            {{ t('airports.col_name') }}
                                        </th>
                                        <th
                                            class="px-4 py-3 text-left text-[var(--binary-outline)] uppercase"
                                        >
                                            {{ t('airports.col_iata') }}
                                        </th>
                                        <th
                                            class="hidden px-4 py-3 text-left text-[var(--binary-outline)] uppercase md:table-cell"
                                        >
                                            {{ t('airports.col_icao') }}
                                        </th>
                                        <th
                                            class="hidden px-4 py-3 text-left text-[var(--binary-outline)] uppercase lg:table-cell"
                                        >
                                            {{ t('airports.col_city') }}
                                        </th>
                                        <th
                                            class="hidden px-4 py-3 text-left text-[var(--binary-outline)] uppercase lg:table-cell"
                                        >
                                            {{ t('airports.col_country') }}
                                        </th>
                                        <th
                                            class="px-4 py-3 text-center text-[var(--binary-outline)] uppercase"
                                        >
                                            {{ t('airports.col_scheduled') }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="airport in airports"
                                        :key="airport.id"
                                        class="border-t border-[var(--binary-outline)]/10 transition hover:bg-[var(--binary-surface-container)]"
                                    >
                                        <td
                                            class="px-4 py-3 text-[var(--binary-text)]"
                                        >
                                            {{ airport.name }}
                                        </td>
                                        <td
                                            class="px-4 py-3 font-bold text-[var(--binary-primary)]"
                                        >
                                            {{ airport.codes.iata ?? '–' }}
                                        </td>
                                        <td
                                            class="hidden px-4 py-3 text-[var(--binary-outline)] md:table-cell"
                                        >
                                            {{ airport.codes.icao ?? '–' }}
                                        </td>
                                        <td
                                            class="hidden px-4 py-3 text-[var(--binary-text-muted)] lg:table-cell"
                                        >
                                            {{
                                                airport.location.municipality ??
                                                '–'
                                            }}
                                        </td>
                                        <td
                                            class="hidden px-4 py-3 text-[var(--binary-outline)] lg:table-cell"
                                        >
                                            {{
                                                airport.location.country ?? '–'
                                            }}
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <span
                                                class="inline-block rounded-full px-2 py-0.5 text-[10px] font-bold uppercase"
                                                :class="
                                                    airport.scheduled_service
                                                        ? 'bg-[var(--binary-primary)]/20 text-[var(--binary-primary)]'
                                                        : 'bg-[var(--binary-surface-container)] text-[var(--binary-outline)]'
                                                "
                                            >
                                                {{
                                                    airport.scheduled_service
                                                        ? 'YES'
                                                        : 'NO'
                                                }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div
                            v-if="meta"
                            class="mt-6 flex flex-col items-center justify-between gap-4 sm:flex-row"
                        >
                            <span class="text-xs text-[var(--binary-outline)]">
                                {{
                                    t('common.total', {
                                        total: meta.total.toLocaleString(),
                                        current: meta.current_page,
                                        last: meta.last_page,
                                    })
                                }}
                            </span>
                            <div class="flex gap-2">
                                <button
                                    class="binary-ghost-button px-4 py-1.5 text-xs disabled:opacity-30"
                                    :disabled="meta.current_page <= 1"
                                    @click="
                                        fetchAirports(meta!.current_page - 1)
                                    "
                                >
                                    {{ t('common.prev_page') }}
                                </button>
                                <button
                                    class="binary-ghost-button px-4 py-1.5 text-xs disabled:opacity-30"
                                    :disabled="
                                        meta.current_page >= meta.last_page
                                    "
                                    @click="
                                        fetchAirports(meta!.current_page + 1)
                                    "
                                >
                                    {{ t('common.next_page') }}
                                </button>
                            </div>
                        </div>
                    </template>
                </template>

                <!-- ── Stats Tab ── -->
                <template v-else-if="activeTab === 'stats'">
                    <div
                        v-if="isLoadingStats"
                        class="py-16 text-center text-sm text-[var(--binary-text-muted)]"
                    >
                        {{ t('airports.stats.loading') }}
                    </div>
                    <p
                        v-else-if="statsError"
                        class="rounded-lg border border-red-400/20 bg-red-950/20 px-4 py-3 text-sm text-red-300"
                    >
                        {{ statsError }}
                    </p>

                    <template v-else-if="stats">
                        <!-- Summary Cards -->
                        <div class="mb-8 grid grid-cols-2 gap-4 md:grid-cols-4">
                            <div
                                class="binary-card-raised rounded-2xl text-center"
                            >
                                <div
                                    class="binary-display text-4xl font-black text-[var(--binary-primary)]"
                                >
                                    {{ stats.total.toLocaleString() }}
                                </div>
                                <div
                                    class="binary-label mt-1 text-[10px] text-[var(--binary-outline)] uppercase"
                                >
                                    {{ t('airports.stats.total') }}
                                </div>
                            </div>
                            <div
                                class="binary-card-raised rounded-2xl text-center"
                            >
                                <div
                                    class="binary-display text-4xl font-black text-[var(--binary-primary)]"
                                >
                                    {{
                                        stats.scheduled_service.toLocaleString()
                                    }}
                                </div>
                                <div
                                    class="binary-label mt-1 text-[10px] text-[var(--binary-outline)] uppercase"
                                >
                                    {{ t('airports.stats.scheduled') }}
                                </div>
                            </div>
                            <div
                                class="binary-card-raised rounded-2xl text-center"
                            >
                                <div
                                    class="binary-display text-4xl font-black text-[var(--binary-primary)]"
                                >
                                    {{ Object.keys(stats.by_continent).length }}
                                </div>
                                <div
                                    class="binary-label mt-1 text-[10px] text-[var(--binary-outline)] uppercase"
                                >
                                    {{ t('airports.stats.continents') }}
                                </div>
                            </div>
                            <div
                                class="binary-card-raised rounded-2xl text-center"
                            >
                                <div
                                    class="binary-display text-4xl font-black text-[var(--binary-primary)]"
                                >
                                    {{
                                        Object.keys(stats.top_countries).length
                                    }}+
                                </div>
                                <div
                                    class="binary-label mt-1 text-[10px] text-[var(--binary-outline)] uppercase"
                                >
                                    {{ t('airports.stats.countries') }}
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <!-- By Type -->
                            <div class="binary-card-raised rounded-2xl">
                                <h3
                                    class="binary-label mb-4 text-xs font-bold text-[var(--binary-outline)] uppercase"
                                >
                                    &gt; {{ t('airports.stats.by_type') }}
                                </h3>
                                <div class="space-y-3">
                                    <div
                                        v-for="[type, count] in topTypeEntries"
                                        :key="type"
                                    >
                                        <div
                                            class="binary-label mb-1 flex items-center justify-between text-xs"
                                        >
                                            <span
                                                class="text-[var(--binary-text)]"
                                                >{{
                                                    t(
                                                        `airports.types.${type}`,
                                                        type,
                                                    )
                                                }}</span
                                            >
                                            <span
                                                class="text-[var(--binary-primary)]"
                                                >{{
                                                    count.toLocaleString()
                                                }}</span
                                            >
                                        </div>
                                        <div
                                            class="h-1.5 w-full rounded-full bg-[var(--binary-surface-container)]"
                                        >
                                            <div
                                                class="h-1.5 rounded-full bg-[var(--binary-primary)] transition-all"
                                                :style="{
                                                    width: `${(count / maxTypeCount) * 100}%`,
                                                }"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- By Continent -->
                            <div class="binary-card-raised rounded-2xl">
                                <h3
                                    class="binary-label mb-4 text-xs font-bold text-[var(--binary-outline)] uppercase"
                                >
                                    &gt; {{ t('airports.stats.by_continent') }}
                                </h3>
                                <div class="space-y-2">
                                    <div
                                        v-for="[
                                            continent,
                                            count,
                                        ] in topContinentEntries"
                                        :key="continent"
                                        class="flex items-center justify-between rounded-lg bg-[var(--binary-surface-container)] px-3 py-2"
                                    >
                                        <span
                                            class="binary-label text-xs text-[var(--binary-text)]"
                                        >
                                            {{
                                                t(
                                                    `airports.continents.${continent}`,
                                                    continent,
                                                )
                                            }}
                                        </span>
                                        <span
                                            class="binary-label text-xs font-bold text-[var(--binary-primary)]"
                                        >
                                            {{ count.toLocaleString() }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Top Countries -->
                            <div
                                class="binary-card-raised rounded-2xl md:col-span-2"
                            >
                                <h3
                                    class="binary-label mb-4 text-xs font-bold text-[var(--binary-outline)] uppercase"
                                >
                                    &gt; {{ t('airports.stats.top_countries') }}
                                </h3>
                                <div
                                    class="grid grid-cols-2 gap-3 sm:grid-cols-5"
                                >
                                    <div
                                        v-for="(
                                            [country, count], i
                                        ) in topCountryEntries"
                                        :key="country"
                                        class="rounded-xl bg-[var(--binary-surface-container)] p-3 text-center"
                                    >
                                        <div
                                            class="binary-label text-[10px] text-[var(--binary-outline)]"
                                        >
                                            #{{ i + 1 }}
                                        </div>
                                        <div
                                            class="binary-display mt-1 text-xl font-black text-[var(--binary-primary)]"
                                        >
                                            {{ country }}
                                        </div>
                                        <div
                                            class="binary-label mt-0.5 text-xs text-[var(--binary-text-muted)]"
                                        >
                                            {{ count.toLocaleString() }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </template>

                <template v-else>
                    <AirportGlobe />
                </template>
            </div>
        </main>
    </AppLayout>
</template>

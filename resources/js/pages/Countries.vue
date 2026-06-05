<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuth } from '../composables/useAuth';
import AppLayout from '../layouts/AppLayout.vue';
import { api } from '../lib/routes';

const { t } = useI18n();
const { user } = useAuth();

interface Country {
    code: string;
    alpha3: string | null;
    name_en: string;
    name_zh_tw: string | null;
    capital: string | null;
    phone_code: string | null;
}

interface City {
    id: number;
    name_en: string;
    name_zh_tw: string | null;
    population: number | null;
    timezone: string | null;
    description: string | null;
}

interface Candidate {
    qid: string;
    name_en: string | null;
    name_zh_tw: string | null;
    description: string | null;
    aliases: string[];
    url: string;
    existing: boolean;
    country_code: string | null;
}

interface JobRecord {
    id: number;
    city_name: string;
    wikidata_qid: string;
    country_code: string;
    status: 'pending' | 'processing' | 'success' | 'failed';
    error: string | null;
    city: { name_zh_tw: string | null; name_en: string } | null;
    created_at: string;
}

// ── Main tabs ──
const mainTab = ref<'cities' | 'jobs'>('cities');

// 國家選擇器：平常框內顯示已選國家，聚焦時清空變回搜尋
const pickerOpen = ref(false);

function onPickerFocus() {
    pickerOpen.value = true;
    search.value = '';
}

// 失焦時延遲關閉，讓清單點擊先觸發
function onPickerBlur() {
    setTimeout(() => {
        pickerOpen.value = false;
    }, 150);
}

// ── Countries ──
const allCountries = ref<Country[]>([]);
const isLoading = ref(false);
const error = ref<string | null>(null);
const search = ref('');

const countries = computed(() => {
    const q = search.value.trim().toUpperCase();

    if (!q) {
        return allCountries.value;
    }

    return allCountries.value.filter(
        (c) =>
            c.code.includes(q) ||
            c.alpha3?.includes(q) ||
            c.name_en.toUpperCase().includes(q) ||
            (c.name_zh_tw ?? '').includes(search.value.trim()),
    );
});

// ── Cities panel ──
const selectedCountry = ref<Country | null>(null);
const cities = ref<City[]>([]);
const isCityLoading = ref(false);
const citySubTab = ref<'list' | 'add'>('list');

// 選擇器框內平常顯示的已選國家名
const selectedCountryName = computed(() =>
    selectedCountry.value
        ? (selectedCountry.value.name_zh_tw ?? selectedCountry.value.name_en)
        : '',
);

// ── City search ──
const cityName = ref('');
const candidates = ref<Candidate[]>([]);
const isSearching = ref(false);
const isSubmitting = ref(false);
const searchError = ref<string | null>(null);

// ── Jobs ──
const jobs = ref<JobRecord[]>([]);
let pollTimer: ReturnType<typeof setInterval> | null = null;

async function fetchCountries() {
    isLoading.value = true;
    error.value = null;

    try {
        const params = new URLSearchParams({
            per_page: '300',
            recognized: '1',
        });
        const res = await fetch(`${api.countries.index()}?${params}`);
        const json = await res.json();
        allCountries.value = json.data;
    } catch {
        error.value = t('common.error_connection');
    } finally {
        isLoading.value = false;
    }
}

async function selectCountry(country: Country) {
    selectedCountry.value = country;
    citySubTab.value = 'list';
    candidates.value = [];
    cityName.value = '';
    searchError.value = null;
    fetchCities();
}

async function fetchCities() {
    if (!selectedCountry.value) {
        return;
    }

    isCityLoading.value = true;
    cities.value = [];

    try {
        const params = new URLSearchParams({
            country_code: selectedCountry.value.code,
        });
        const res = await fetch(`${api.cities.index()}?${params}`);
        const json = await res.json();
        cities.value = json.data ?? [];
    } finally {
        isCityLoading.value = false;
    }
}

async function searchCity() {
    if (!cityName.value || !selectedCountry.value) {
        return;
    }

    isSearching.value = true;
    searchError.value = null;
    candidates.value = [];

    try {
        const params = new URLSearchParams({ city_name: cityName.value });
        const res = await fetch(`${api.cities.preview()}?${params}`);
        const json = await res.json();
        candidates.value = json.data ?? [];

        if (candidates.value.length === 0) {
            searchError.value = t('city_search.no_candidates');
        }
    } catch {
        searchError.value = t('common.error_connection');
    } finally {
        isSearching.value = false;
    }
}

async function confirmCandidate(candidate: Candidate) {
    if (isSubmitting.value || !selectedCountry.value) {
        return;
    }

    isSubmitting.value = true;

    try {
        const res = await fetch(api.cities.search.index(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            credentials: 'include',
            body: JSON.stringify({
                city_name:
                    candidate.name_zh_tw ?? candidate.name_en ?? cityName.value,
                wikidata_qid: candidate.qid,
                country_code: selectedCountry.value.code,
            }),
        });

        if (res.ok) {
            candidates.value = [];
            cityName.value = '';
            mainTab.value = 'jobs';
            await fetchJobs();
            startPolling();
        }
    } finally {
        isSubmitting.value = false;
    }
}

async function fetchJobs() {
    if (!user.value) {
        return;
    }

    try {
        const res = await fetch(api.cities.search.index(), {
            credentials: 'include',
        });

        if (!res.ok) {
            return;
        }

        const json = await res.json();
        jobs.value = json.data ?? [];
    } catch {
        /* not logged in, ignore */
    }
}

function startPolling() {
    if (pollTimer) {
        return;
    }

    pollTimer = setInterval(async () => {
        await fetchJobs();
        const hasActive = jobs.value.some(
            (j) => j.status === 'pending' || j.status === 'processing',
        );

        if (!hasActive) {
            clearInterval(pollTimer!);
            pollTimer = null;

            if (selectedCountry.value) {
                fetchCities();
            }
        }
    }, 2500);
}

async function openJobsTab() {
    mainTab.value = 'jobs';
    await fetchJobs();

    if (
        jobs.value.some(
            (j) => j.status === 'pending' || j.status === 'processing',
        )
    ) {
        startPolling();
    }
}

onMounted(() => fetchCountries());
</script>

<template>
    <AppLayout>
        <Head title="Countries" />

        <div class="mx-auto max-w-screen-xl px-[18px] pb-24 md:px-8">
            <!-- Header -->
            <div class="mb-6 pt-8">
                <span
                    class="binary-label mb-2 block text-xs font-bold text-[var(--binary-primary)] uppercase"
                    >&gt; country_database</span
                >
                <h1 class="binary-page-title">
                    {{ t('countries.title').toUpperCase() }}
                </h1>
                <p class="mt-3 text-sm text-[var(--binary-text-muted)]">
                    {{ t('countries.subtitle') }}
                </p>
            </div>

            <!-- Top-level tabs -->
            <div
                class="mb-5 flex items-center border-b border-[var(--binary-outline-variant)]"
            >
                <button
                    class="binary-label mr-6 border-b-2 pb-2.5 text-[10px] uppercase transition"
                    :class="
                        mainTab === 'cities'
                            ? 'border-[var(--binary-primary)] text-[var(--binary-primary)]'
                            : 'border-transparent text-[var(--binary-outline)] hover:text-[var(--binary-text)]'
                    "
                    @click="mainTab = 'cities'"
                >
                    {{ t('countries.title') }}
                </button>
                <button
                    class="binary-label mr-6 border-b-2 pb-2.5 text-[10px] uppercase transition"
                    :class="
                        mainTab === 'jobs'
                            ? 'border-[var(--binary-primary)] text-[var(--binary-primary)]'
                            : 'border-transparent text-[var(--binary-outline)] hover:text-[var(--binary-text)]'
                    "
                    @click="openJobsTab"
                >
                    {{ t('city_search.tab_jobs') }}
                </button>
            </div>

            <!-- ── Tab: cities ── -->
            <div
                v-if="mainTab === 'cities'"
                class="flex h-[640px] flex-col gap-0 md:flex-row md:gap-4"
            >
                <!-- Left: country picker（手機下拉 / 桌機側欄） -->
                <div
                    class="relative flex flex-shrink-0 flex-col overflow-visible border border-[var(--binary-outline-variant)] md:w-60 md:overflow-hidden md:rounded-xl"
                >
                    <!-- 搜尋框（常駐；手機聚焦即下拉） -->
                    <div
                        class="flex flex-shrink-0 items-center gap-2 border-b border-[var(--binary-outline-variant)] bg-[var(--binary-surface-container)] px-4 py-3"
                    >
                        <!-- 手機：combobox（顯示已選國家、聚焦變搜尋） -->
                        <input
                            :value="pickerOpen ? search : selectedCountryName"
                            type="text"
                            :placeholder="t('common.search')"
                            class="min-w-0 flex-1 bg-transparent text-sm text-[var(--binary-text)] outline-none placeholder:text-[var(--binary-outline)] md:hidden"
                            @input="
                                search = ($event.target as HTMLInputElement)
                                    .value
                            "
                            @focus="onPickerFocus"
                            @blur="onPickerBlur"
                        />
                        <!-- 桌機：原本的純搜尋輸入（邏輯不變） -->
                        <input
                            v-model="search"
                            type="text"
                            :placeholder="t('common.search')"
                            class="hidden min-w-0 flex-1 bg-transparent text-sm text-[var(--binary-text)] outline-none placeholder:text-[var(--binary-outline)] md:block"
                        />
                        <span
                            v-if="allCountries.length"
                            class="binary-label flex-shrink-0 text-xs text-[var(--binary-outline)]"
                            >{{ countries.length }}</span
                        >
                    </div>

                    <!-- 清單：桌機常駐；手機聚焦時絕對下拉 -->
                    <div
                        class="flex-col overflow-hidden md:flex md:flex-1"
                        :class="
                            pickerOpen
                                ? 'absolute inset-x-0 top-full z-20 flex max-h-[55vh] border border-[var(--binary-outline-variant)] bg-[var(--binary-background)] shadow-xl'
                                : 'hidden md:flex'
                        "
                    >
                        <div
                            v-if="isLoading"
                            class="flex flex-1 items-center justify-center text-xs text-[var(--binary-outline)]"
                        >
                            {{ t('common.loading') }}
                        </div>
                        <div
                            v-else-if="error"
                            class="flex flex-1 items-center justify-center text-xs text-red-400"
                        >
                            {{ error }}
                        </div>
                        <div v-else class="flex-1 overflow-y-auto">
                            <button
                                v-for="country in countries"
                                :key="country.code"
                                class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm transition hover:bg-[var(--binary-surface-container)]"
                                :class="
                                    selectedCountry?.code === country.code
                                        ? 'bg-[var(--binary-surface-container)] text-[var(--binary-primary)]'
                                        : 'text-[var(--binary-text)]'
                                "
                                @click="
                                    selectCountry(country);
                                    pickerOpen = false;
                                "
                            >
                                <span
                                    class="w-7 flex-shrink-0 font-mono text-xs text-[var(--binary-outline)]"
                                    >{{ country.code }}</span
                                >
                                <span class="truncate">{{
                                    country.name_zh_tw ?? country.name_en
                                }}</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Right: country detail panel -->
                <div
                    class="flex flex-1 flex-col overflow-hidden rounded-none border border-[var(--binary-outline-variant)] md:rounded-xl"
                >
                    <div
                        v-if="!selectedCountry"
                        class="flex flex-1 items-center justify-center text-sm text-[var(--binary-outline)]"
                    >
                        {{ t('countries.select_hint') }}
                    </div>
                    <template v-else>
                        <!-- Country header -->
                        <div
                            class="flex-shrink-0 border-b border-[var(--binary-outline-variant)] bg-[var(--binary-surface-container)]"
                        >
                            <div
                                class="hidden items-center gap-3 px-5 py-3 md:flex"
                            >
                                <span
                                    class="font-mono text-xs text-[var(--binary-outline)]"
                                    >{{ selectedCountry.code }}</span
                                >
                                <span
                                    class="text-sm font-medium text-[var(--binary-text)]"
                                    >{{
                                        selectedCountry.name_zh_tw ??
                                        selectedCountry.name_en
                                    }}</span
                                >
                                <span
                                    v-if="cities.length"
                                    class="binary-label text-[10px] text-[var(--binary-outline)]"
                                    >{{ cities.length }}
                                    {{ t('countries.cities') }}</span
                                >
                            </div>
                            <!-- Sub-tabs (only for verified users) -->
                            <div
                                v-if="user && user.email_verified_at"
                                class="flex px-5"
                            >
                                <button
                                    class="binary-label mr-5 border-b-2 pb-2 text-[10px] uppercase transition"
                                    :class="
                                        citySubTab === 'list'
                                            ? 'border-[var(--binary-primary)] text-[var(--binary-primary)]'
                                            : 'border-transparent text-[var(--binary-outline)] hover:text-[var(--binary-text)]'
                                    "
                                    @click="citySubTab = 'list'"
                                >
                                    {{ t('countries.tab_cities') }}
                                </button>
                                <button
                                    class="binary-label border-b-2 pb-2 text-[10px] uppercase transition"
                                    :class="
                                        citySubTab === 'add'
                                            ? 'border-[var(--binary-primary)] text-[var(--binary-primary)]'
                                            : 'border-transparent text-[var(--binary-outline)] hover:text-[var(--binary-text)]'
                                    "
                                    @click="citySubTab = 'add'"
                                >
                                    {{ t('countries.add_city') }}
                                </button>
                            </div>
                        </div>

                        <!-- Sub-tab: city list -->
                        <div
                            v-if="citySubTab === 'list'"
                            class="flex-1 overflow-y-auto"
                        >
                            <div
                                v-if="isCityLoading"
                                class="flex h-full items-center justify-center text-xs text-[var(--binary-outline)]"
                            >
                                {{ t('common.loading') }}
                            </div>
                            <div
                                v-else-if="cities.length === 0"
                                class="flex h-full items-center justify-center text-sm text-[var(--binary-outline)]"
                            >
                                {{ t('common.no_data') }}
                            </div>
                            <div
                                v-else
                                class="grid grid-cols-2 gap-px bg-[var(--binary-outline-variant)] md:grid-cols-3 lg:grid-cols-4"
                            >
                                <div
                                    v-for="city in cities"
                                    :key="city.id"
                                    class="bg-[var(--binary-surface-dim)] px-4 py-3"
                                >
                                    <p
                                        class="text-sm font-medium text-[var(--binary-text)]"
                                    >
                                        {{ city.name_zh_tw ?? city.name_en }}
                                    </p>
                                    <p
                                        class="text-xs text-[var(--binary-outline)]"
                                    >
                                        {{ city.name_en }}
                                    </p>
                                    <p
                                        v-if="city.population"
                                        class="binary-label mt-0.5 text-[10px] text-[var(--binary-text-muted)]"
                                    >
                                        {{ city.population.toLocaleString() }}
                                    </p>
                                    <p
                                        v-if="city.timezone"
                                        class="binary-label text-[10px] text-[var(--binary-outline)]"
                                    >
                                        {{ city.timezone }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Sub-tab: add city -->
                        <div
                            v-else-if="citySubTab === 'add'"
                            class="flex-1 overflow-y-auto px-5 py-4"
                        >
                            <!-- City name + search -->
                            <div class="mb-5 flex gap-3">
                                <input
                                    v-model="cityName"
                                    type="text"
                                    :placeholder="t('city_search.placeholder')"
                                    class="flex-1 rounded-lg border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-container)] px-4 py-2.5 text-sm text-[var(--binary-text)] placeholder:text-[var(--binary-outline)] focus:border-[var(--binary-primary)] focus:outline-none"
                                    @keydown.enter="searchCity"
                                />
                                <button
                                    :disabled="isSearching || !cityName"
                                    class="binary-label rounded-lg bg-[var(--binary-surface-container)] px-4 py-2.5 text-xs text-[var(--binary-primary)] uppercase transition hover:bg-[var(--binary-surface-high)] disabled:opacity-40"
                                    @click="searchCity"
                                >
                                    {{
                                        isSearching
                                            ? t('common.loading')
                                            : t('city_search.search')
                                    }}
                                </button>
                            </div>

                            <div
                                v-if="searchError"
                                class="mb-4 text-sm text-[var(--binary-tertiary)]"
                            >
                                {{ searchError }}
                            </div>

                            <!-- Candidates -->
                            <div v-if="candidates.length" class="space-y-3">
                                <div
                                    v-for="c in candidates"
                                    :key="c.qid"
                                    class="flex items-start justify-between gap-4 rounded-none border bg-[var(--binary-surface-container)] px-5 py-4 md:rounded-xl"
                                    :class="
                                        c.existing
                                            ? 'border-[var(--binary-outline)]'
                                            : 'border-[var(--binary-outline-variant)]'
                                    "
                                >
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <p
                                                class="font-medium text-[var(--binary-text)]"
                                            >
                                                {{ c.name_zh_tw ?? c.name_en }}
                                            </p>
                                            <span
                                                v-if="c.existing"
                                                class="binary-label rounded bg-[var(--binary-primary)]/10 px-1.5 py-0.5 text-[10px] text-[var(--binary-primary)] uppercase"
                                            >
                                                已存在 {{ c.country_code }}
                                            </span>
                                        </div>
                                        <p
                                            v-if="c.name_zh_tw && c.name_en"
                                            class="text-xs text-[var(--binary-outline)]"
                                        >
                                            {{ c.name_en }}
                                        </p>
                                        <p
                                            v-if="c.description"
                                            class="mt-1 text-xs text-[var(--binary-text-muted)]"
                                        >
                                            {{ c.description }}
                                        </p>
                                        <p
                                            v-if="c.aliases.length"
                                            class="mt-1 text-[10px] text-[var(--binary-outline)]"
                                        >
                                            {{ c.aliases.join(' · ') }}
                                        </p>
                                        <a
                                            :href="c.url"
                                            target="_blank"
                                            class="binary-label mt-1 inline-block text-[10px] text-[var(--binary-primary)] uppercase hover:underline"
                                        >
                                            {{ c.qid }} ↗
                                        </a>
                                    </div>
                                    <button
                                        :disabled="isSubmitting || c.existing"
                                        class="binary-label flex-shrink-0 rounded-lg px-3 py-1.5 text-[10px] uppercase transition disabled:opacity-40"
                                        :class="
                                            c.existing
                                                ? 'cursor-not-allowed bg-[var(--binary-surface-high)] text-[var(--binary-outline)]'
                                                : 'bg-[var(--binary-primary)]/10 text-[var(--binary-primary)] hover:bg-[var(--binary-primary)]/20'
                                        "
                                        @click="
                                            !c.existing && confirmCandidate(c)
                                        "
                                    >
                                        {{
                                            c.existing
                                                ? '已存在'
                                                : t('city_search.confirm')
                                        }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- ── Tab: jobs ── -->
            <div v-else class="mx-auto max-w-2xl">
                <div
                    v-if="!user"
                    class="flex h-48 items-center justify-center text-sm text-[var(--binary-outline)]"
                >
                    {{ t('city_search.login_required') }}
                </div>
                <div
                    v-else-if="jobs.length === 0"
                    class="flex h-48 items-center justify-center text-sm text-[var(--binary-outline)]"
                >
                    {{ t('common.no_data') }}
                </div>
                <div v-else class="space-y-3">
                    <div
                        v-for="job in jobs"
                        :key="job.id"
                        class="flex items-center gap-4 rounded-none border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-container)] px-5 py-3 md:rounded-xl"
                    >
                        <span
                            class="binary-label flex-shrink-0 rounded px-2 py-0.5 text-[10px] uppercase"
                            :class="{
                                'bg-yellow-500/10 text-yellow-400':
                                    job.status === 'pending' ||
                                    job.status === 'processing',
                                'bg-green-500/10 text-green-400':
                                    job.status === 'success',
                                'bg-red-500/10 text-red-400':
                                    job.status === 'failed',
                            }"
                            >{{ job.status }}</span
                        >
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-[var(--binary-text)]">
                                {{
                                    job.city?.name_zh_tw ??
                                    job.city?.name_en ??
                                    job.city_name
                                }}
                            </p>
                            <p
                                class="binary-label text-[10px] text-[var(--binary-outline)]"
                            >
                                {{ job.country_code }} · {{ job.wikidata_qid }}
                            </p>
                            <p v-if="job.error" class="text-xs text-red-400">
                                {{ job.error }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

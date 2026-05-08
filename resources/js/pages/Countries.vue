<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../layouts/AppLayout.vue';
import { api, routes } from '../lib/routes';

const { t } = useI18n();

interface Country {
    code:       string;
    alpha3:     string | null;
    name_en:    string;
    name_zh_tw: string | null;
    capital:    string | null;
    phone_code: string | null;
}

interface City {
    id:          number;
    name_en:     string;
    name_zh_tw:  string | null;
    population:  number | null;
    timezone:    string | null;
    description: string | null;
    image_url:   string | null;
}


const allCountries    = ref<Country[]>([]);
const isLoading       = ref(false);
const error           = ref<string | null>(null);
const selectedCountry = ref<Country | null>(null);
const cities          = ref<City[]>([]);
const isCityLoading   = ref(false);
const search          = ref('');

const countries = computed(() => {
    const q = search.value.trim().toUpperCase();
    if (!q) return allCountries.value;
    return allCountries.value.filter(c =>
        c.code.includes(q) ||
        c.alpha3?.includes(q) ||
        c.name_en.toUpperCase().includes(q) ||
        (c.name_zh_tw ?? '').includes(search.value.trim())
    );
});

async function fetchCountries() {
    isLoading.value = true;
    error.value     = null;
    try {
        const params    = new URLSearchParams({ per_page: '300', recognized: '1' });
        const res       = await fetch(`${api.countries.index()}?${params}`);
        const json      = await res.json();
        allCountries.value = json.data;
    } catch {
        error.value = t('common.error_connection');
    } finally {
        isLoading.value = false;
    }
}

async function selectCountry(country: Country) {
    selectedCountry.value = country;
    cities.value          = [];
    isCityLoading.value   = true;
    try {
        const params = new URLSearchParams({ country_code: country.code });
        const res    = await fetch(`${api.cities.index()}?${params}`);
        const json   = await res.json();
        cities.value = json.data ?? [];
    } finally {
        isCityLoading.value = false;
    }
}

onMounted(() => fetchCountries());
</script>

<template>
    <AppLayout>
        <Head title="Countries" />

        <div class="mx-auto max-w-screen-xl px-6 pb-24 pt-32 md:px-8">
            <!-- Header -->
            <div class="mb-8 pt-8">
                <span class="binary-label mb-2 block text-xs font-bold uppercase text-[var(--binary-primary)]">&gt; country_database</span>
                <h1 class="binary-display text-5xl font-black uppercase tracking-tight md:text-7xl">{{ t('countries.title').toUpperCase() }}</h1>
                <p class="mt-3 text-sm text-[var(--binary-text-muted)]">{{ t('countries.subtitle') }}</p>
            </div>

            <!-- Split layout -->
            <div class="flex gap-4" style="height: 620px;">
                <!-- Left: country list -->
                <div class="flex w-60 flex-shrink-0 flex-col rounded-xl border border-[var(--binary-outline-variant)] overflow-hidden">
                    <div class="bg-[var(--binary-surface-container)] flex items-center gap-2 px-4 py-3 flex-shrink-0 border-b border-[var(--binary-outline-variant)]">
                        <input
                            v-model="search"
                            type="text"
                            :placeholder="t('common.search')"
                            class="min-w-0 flex-1 bg-transparent text-sm text-[var(--binary-text)] placeholder:text-[var(--binary-outline)] outline-none"
                        >
                        <span v-if="allCountries.length" class="binary-label flex-shrink-0 text-xs text-[var(--binary-outline)]">{{ countries.length }}</span>
                    </div>
                    <div v-if="isLoading" class="flex flex-1 items-center justify-center text-xs text-[var(--binary-outline)]">{{ t('common.loading') }}</div>
                    <div v-else-if="error" class="flex flex-1 items-center justify-center text-xs text-red-400">{{ error }}</div>
                    <div v-else class="flex-1 overflow-y-auto">
                        <button
                            v-for="country in countries"
                            :key="country.code"
                            class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm transition hover:bg-[var(--binary-surface-container)]"
                            :class="selectedCountry?.code === country.code ? 'bg-[var(--binary-surface-container)] text-[var(--binary-primary)]' : 'text-[var(--binary-text)]'"
                            @click="selectCountry(country)"
                        >
                            <span class="font-mono text-xs text-[var(--binary-outline)] w-7 flex-shrink-0">{{ country.code }}</span>
                            <span class="truncate">{{ country.name_zh_tw ?? country.name_en }}</span>
                        </button>
                    </div>
                </div>

                <!-- Right: cities -->
                <div class="flex flex-1 flex-col rounded-xl border border-[var(--binary-outline-variant)] overflow-hidden">
                    <div class="bg-[var(--binary-surface-container)] binary-label px-4 py-2 text-[10px] uppercase tracking-wider text-[var(--binary-outline)] flex items-center justify-between flex-shrink-0">
                        <span>
                            <template v-if="selectedCountry">
                                {{ selectedCountry.name_zh_tw ?? selectedCountry.name_en }}
                                <span class="ml-1 font-mono">{{ selectedCountry.code }}</span>
                                <span v-if="cities.length" class="ml-1 opacity-60">{{ cities.length }} {{ t('countries.cities') }}</span>
                            </template>
                            <template v-else>{{ t('countries.select_hint') }}</template>
                        </span>
                        <a
                            v-if="selectedCountry"
                            :href="routes.citySearch(selectedCountry.code)"
                            target="_blank"
                            class="binary-label text-[10px] uppercase text-[var(--binary-primary)] hover:underline"
                        >
                            + {{ t('countries.add_city') }}
                        </a>
                    </div>

                    <div v-if="!selectedCountry" class="flex flex-1 items-center justify-center text-sm text-[var(--binary-outline)]">{{ t('countries.select_hint') }}</div>
                    <div v-else-if="isCityLoading" class="flex flex-1 items-center justify-center text-xs text-[var(--binary-outline)]">{{ t('common.loading') }}</div>
                    <div v-else-if="cities.length === 0" class="flex flex-1 flex-col items-center justify-center gap-3 text-sm text-[var(--binary-outline)]">
                        <span>{{ t('common.no_data') }}</span>
                        <a
                            :href="routes.citySearch(selectedCountry.code)"
                            target="_blank"
                            class="binary-label text-xs uppercase text-[var(--binary-primary)] hover:underline"
                        >{{ t('countries.add_city') }}</a>
                    </div>
                    <div v-else class="flex-1 overflow-y-auto">
                        <div class="grid grid-cols-2 gap-px bg-[var(--binary-outline-variant)] md:grid-cols-3 lg:grid-cols-4">
                            <div v-for="city in cities" :key="city.id" class="bg-[var(--binary-surface-dim)] px-4 py-3">
                                <p class="text-sm font-medium text-[var(--binary-text)]">{{ city.name_zh_tw ?? city.name_en }}</p>
                                <p class="text-xs text-[var(--binary-outline)]">{{ city.name_en }}</p>
                                <p v-if="city.population" class="mt-0.5 binary-label text-[10px] text-[var(--binary-text-muted)]">{{ city.population.toLocaleString() }}</p>
                                <p v-if="city.timezone" class="binary-label text-[10px] text-[var(--binary-outline)]">{{ city.timezone }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

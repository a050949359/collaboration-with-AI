<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, reactive, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../layouts/AppLayout.vue';
import { api } from '../lib/routes';

const { t } = useI18n();

interface Country {
    code:          string;
    alpha3:        string | null;
    numeric:       string | null;
    name_en:       string;
    name_zh_tw:    string | null;
    capital:       string | null;
    phone_code:    string | null;
    is_recognized: boolean;
}

interface Meta {
    current_page: number;
    last_page:    number;
    per_page:     number;
    total:        number;
}

const countries = ref<Country[]>([]);
const meta      = ref<Meta | null>(null);
const isLoading = ref(false);
const error     = ref<string | null>(null);

const filters = reactive({
    search:     '',
    recognized: '' as '' | '1' | '0',
    page:       1,
});

let searchTimer: number | null = null;

function flagEmoji(code: string): string {
    return [...code.toUpperCase()].map(c => String.fromCodePoint(127397 + c.charCodeAt(0))).join('');
}

async function fetchCountries(page = 1) {
    isLoading.value = true;
    error.value     = null;

    try {
        const params = new URLSearchParams({ per_page: '50', page: String(page) });
        if (filters.search)     params.set('search', filters.search);
        if (filters.recognized) params.set('recognized', filters.recognized);

        const res  = await fetch(`${api.countries.index()}?${params}`);
        const json = await res.json();
        countries.value = json.data;
        meta.value      = json.meta;
        filters.page    = page;
    } catch {
        error.value = t('common.error_connection');
    } finally {
        isLoading.value = false;
    }
}

function onSearchInput() {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => fetchCountries(1), 300);
}

onMounted(() => fetchCountries(1));
</script>

<template>
    <AppLayout>
        <Head title="Countries" />

        <div class="mx-auto max-w-screen-xl px-6 pb-24 pt-32 md:px-8">
            <!-- Header -->
            <div class="mb-10 pt-8">
                <span class="binary-label mb-2 block text-xs font-bold uppercase text-[var(--binary-primary)]">&gt; country_database</span>
                <h1 class="binary-display text-5xl font-black uppercase tracking-tight md:text-7xl">{{ t('countries.title').toUpperCase() }}</h1>
                <p class="mt-3 text-sm text-[var(--binary-text-muted)]">{{ t('countries.subtitle') }}</p>
                <p class="mt-2 text-xs text-[var(--binary-outline)]">
                    {{ t('countries.source_label') }}
                    <a
                        href="https://www.wikidata.org/"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="underline underline-offset-2 hover:text-[var(--binary-primary)]"
                    >{{ t('countries.source_link_wiki') }}</a>
                </p>
            </div>

            <!-- Filters -->
            <div class="mb-8 flex flex-wrap gap-3">
                <input
                    v-model="filters.search"
                    type="text"
                    :placeholder="t('common.search') + ' — name, ISO code'"
                    class="w-full max-w-sm rounded-lg border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-container)] px-4 py-2 text-sm text-[var(--binary-text)] placeholder:text-[var(--binary-outline)] focus:border-[var(--binary-primary)] focus:outline-none"
                    @input="onSearchInput"
                >
                <select
                    v-model="filters.recognized"
                    class="rounded-lg border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-container)] px-3 py-2 text-sm text-[var(--binary-text)] focus:border-[var(--binary-primary)] focus:outline-none"
                    @change="fetchCountries(1)"
                >
                    <option value="">{{ t('common.all') }}</option>
                    <option value="1">{{ t('countries.recognized') }}</option>
                    <option value="0">{{ t('countries.unrecognized') }}</option>
                </select>
            </div>

            <!-- Loading -->
            <div v-if="isLoading" class="py-20 text-center binary-label text-sm text-[var(--binary-outline)]">
                {{ t('common.loading') }}
            </div>

            <!-- Error -->
            <div v-else-if="error" class="py-20 text-center binary-label text-sm text-red-400">
                {{ error }}
            </div>

            <!-- Table -->
            <template v-else>
                <div v-if="countries.length === 0" class="py-20 text-center binary-label text-sm text-[var(--binary-outline)]">
                    {{ t('common.no_data') }}
                </div>

                <div v-else class="overflow-x-auto rounded-xl border border-[var(--binary-outline-variant)]">
                    <table class="w-full text-sm">
                        <thead class="bg-[var(--binary-surface-container)] binary-label text-[10px] uppercase tracking-wider text-[var(--binary-outline)]">
                            <tr>
                                <th class="px-4 py-3 text-left">{{ t('countries.col_code') }}</th>
                                <th class="px-4 py-3 text-left">{{ t('countries.col_name_zh') }}</th>
                                <th class="px-4 py-3 text-left">{{ t('countries.col_name_en') }}</th>
                                <th class="px-4 py-3 text-left">{{ t('countries.col_alpha3') }}</th>
                                <th class="px-4 py-3 text-left">{{ t('countries.col_status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--binary-outline-variant)]">
                            <tr
                                v-for="country in countries"
                                :key="country.code"
                                class="hover:bg-[var(--binary-surface-container)] transition-colors"
                            >
                                <td class="px-4 py-3 font-mono font-bold text-[var(--binary-primary)]">
                                    <span class="mr-2 text-base">{{ flagEmoji(country.code) }}</span>{{ country.code }}
                                </td>
                                <td class="px-4 py-3 text-[var(--binary-text)]">{{ country.name_zh_tw ?? '—' }}</td>
                                <td class="px-4 py-3 text-[var(--binary-text)]">{{ country.name_en }}</td>
                                <td class="px-4 py-3 font-mono text-[var(--binary-outline)]">{{ country.alpha3 ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <span
                                        class="binary-label rounded px-2 py-0.5 text-[10px] uppercase"
                                        :class="country.is_recognized
                                            ? 'bg-green-500/10 text-green-400'
                                            : 'bg-yellow-500/10 text-yellow-400'"
                                    >
                                        {{ country.is_recognized ? t('countries.recognized') : t('countries.unrecognized') }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="meta && meta.last_page > 1" class="mt-6 flex items-center justify-between binary-label text-xs text-[var(--binary-outline)]">
                    <span>{{ t('common.total', { total: meta.total, current: meta.current_page, last: meta.last_page }) }}</span>
                    <div class="flex gap-2">
                        <button
                            :disabled="meta.current_page <= 1"
                            class="rounded px-3 py-1.5 transition hover:text-[var(--binary-primary)] disabled:opacity-30"
                            @click="fetchCountries(meta!.current_page - 1)"
                        >
                            {{ t('common.prev_page') }}
                        </button>
                        <button
                            :disabled="meta.current_page >= meta.last_page"
                            class="rounded px-3 py-1.5 transition hover:text-[var(--binary-primary)] disabled:opacity-30"
                            @click="fetchCountries(meta!.current_page + 1)"
                        >
                            {{ t('common.next_page') }}
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>

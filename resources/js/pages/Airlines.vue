<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, reactive, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../layouts/AppLayout.vue';
import { api } from '../lib/routes';

const { t, locale } = useI18n();

interface Airline {
    id: number;
    iata: string | null;
    icao: string | null;
    name_en: string;
    name_zh_tw: string | null;
    alias_en: string | null;
    alias_zh_tw: string | null;
    nationality: string | null;
}

interface Meta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

const airlines = ref<Airline[]>([]);
const meta = ref<Meta | null>(null);
const isLoading = ref(false);
const error = ref<string | null>(null);

const filters = reactive({
    search: '',
    page: 1,
});

let searchTimer: number | null = null;

async function fetchAirlines(page = 1) {
    isLoading.value = true;
    error.value = null;

    try {
        const params = new URLSearchParams({
            per_page: '50',
            page: String(page),
        });

        if (filters.search) {
            params.set('search', filters.search);
        }

        const res = await fetch(`${api.airlines.index()}?${params}`);
        const json = await res.json();
        airlines.value = json.data;
        meta.value = json.meta;
        filters.page = page;
    } catch {
        error.value = t('common.error_connection');
    } finally {
        isLoading.value = false;
    }
}

function onSearchInput() {
    if (searchTimer) {
        clearTimeout(searchTimer);
    }

    searchTimer = window.setTimeout(() => fetchAirlines(1), 300);
}

onMounted(() => fetchAirlines(1));
</script>

<template>
    <AppLayout>
        <Head :title="t('airlines.title')" />

        <div class="mx-auto max-w-screen-xl px-[18px] pb-24 md:px-8">
            <!-- Header -->
            <div class="mb-10 pt-8">
                <span
                    class="binary-label mb-2 block text-xs font-bold text-[var(--binary-primary)] uppercase"
                    >&gt; airline_database</span
                >
                <h1 class="binary-page-title">
                    {{ t('airlines.title').toUpperCase() }}
                </h1>
                <p class="mt-3 text-sm text-[var(--binary-text-muted)]">
                    {{ t('airlines.subtitle') }}
                </p>
                <p class="mt-2 text-xs text-[var(--binary-outline)]">
                    {{ t('airlines.source_label') }}
                    <a
                        href="https://www.caa.gov.tw/"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="underline underline-offset-2 hover:text-[var(--binary-primary)]"
                        >{{ t('airlines.source_link_caa') }}</a
                    >
                    <span class="mx-1">/</span>
                    <a
                        href="https://www.wikidata.org/"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="underline underline-offset-2 hover:text-[var(--binary-primary)]"
                        >{{ t('airlines.source_link_wiki') }}</a
                    >
                </p>
            </div>

            <!-- Search -->
            <div class="mb-8 flex gap-3">
                <input
                    v-model="filters.search"
                    type="text"
                    :placeholder="t('common.search') + ' — name, IATA, ICAO'"
                    class="w-full max-w-sm rounded-lg border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-container)] px-4 py-2 text-sm text-[var(--binary-text)] placeholder:text-[var(--binary-outline)] focus:border-[var(--binary-primary)] focus:outline-none"
                    @input="onSearchInput"
                />
            </div>

            <!-- Loading -->
            <div
                v-if="isLoading"
                class="binary-label py-20 text-center text-sm text-[var(--binary-outline)]"
            >
                {{ t('common.loading') }}
            </div>

            <!-- Error -->
            <div
                v-else-if="error"
                class="binary-label py-20 text-center text-sm text-red-400"
            >
                {{ error }}
            </div>

            <!-- Table -->
            <template v-else>
                <div
                    v-if="airlines.length === 0"
                    class="binary-label py-20 text-center text-sm text-[var(--binary-outline)]"
                >
                    {{ t('common.no_data') }}
                </div>

                <div
                    v-else
                    class="overflow-x-auto rounded-xl border border-[var(--binary-outline-variant)]"
                >
                    <table class="w-full text-sm">
                        <thead
                            class="binary-label bg-[var(--binary-surface-container)] text-[10px] tracking-wider text-[var(--binary-outline)] uppercase"
                        >
                            <tr>
                                <th class="px-4 py-3 text-left">IATA</th>
                                <th class="px-4 py-3 text-left">ICAO</th>
                                <th
                                    class="px-4 py-3 text-left"
                                    :class="
                                        locale === 'en'
                                            ? ''
                                            : 'hidden md:table-cell'
                                    "
                                >
                                    {{ t('airlines.col_name_en') }}
                                </th>
                                <th
                                    class="px-4 py-3 text-left"
                                    :class="
                                        locale === 'zh-tw'
                                            ? ''
                                            : 'hidden md:table-cell'
                                    "
                                >
                                    {{ t('airlines.col_name_zh') }}
                                </th>
                                <th class="px-4 py-3 text-left">
                                    {{ t('airlines.col_nationality') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-[var(--binary-outline-variant)]"
                        >
                            <tr
                                v-for="airline in airlines"
                                :key="airline.id"
                                class="transition-colors hover:bg-[var(--binary-surface-container)]"
                            >
                                <td
                                    class="px-4 py-3 font-mono font-bold text-[var(--binary-primary)]"
                                >
                                    {{ airline.iata ?? '—' }}
                                </td>
                                <td
                                    class="px-4 py-3 font-mono text-[var(--binary-outline)]"
                                >
                                    {{ airline.icao ?? '—' }}
                                </td>
                                <td
                                    class="px-4 py-3 text-[var(--binary-text)]"
                                    :class="
                                        locale === 'en'
                                            ? ''
                                            : 'hidden md:table-cell'
                                    "
                                >
                                    {{ airline.name_en }}
                                </td>
                                <td
                                    class="px-4 py-3 text-[var(--binary-text)]"
                                    :class="
                                        locale === 'zh-tw'
                                            ? ''
                                            : 'hidden md:table-cell'
                                    "
                                >
                                    {{ airline.name_zh_tw ?? '—' }}
                                </td>
                                <td
                                    class="px-4 py-3 text-[var(--binary-outline)]"
                                >
                                    {{ airline.nationality ?? '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div
                    v-if="meta && meta.last_page > 1"
                    class="binary-label mt-6 flex items-center justify-between text-xs text-[var(--binary-outline)]"
                >
                    <span>{{
                        t('common.total', {
                            total: meta.total,
                            current: meta.current_page,
                            last: meta.last_page,
                        })
                    }}</span>
                    <div class="flex gap-2">
                        <button
                            :disabled="meta.current_page <= 1"
                            class="rounded px-3 py-1.5 transition hover:text-[var(--binary-primary)] disabled:opacity-30"
                            @click="fetchAirlines(meta!.current_page - 1)"
                        >
                            {{ t('common.prev_page') }}
                        </button>
                        <button
                            :disabled="meta.current_page >= meta.last_page"
                            class="rounded px-3 py-1.5 transition hover:text-[var(--binary-primary)] disabled:opacity-30"
                            @click="fetchAirlines(meta!.current_page + 1)"
                        >
                            {{ t('common.next_page') }}
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>

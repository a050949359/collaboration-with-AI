<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../layouts/AppLayout.vue';
import { api } from '../lib/routes';
import { useAuth } from '../composables/useAuth';

const { t } = useI18n();
const { user } = useAuth();

interface Candidate {
    qid:         string;
    name_en:     string | null;
    name_zh_tw:  string | null;
    description: string | null;
    url:         string;
}

interface JobRecord {
    id:           number;
    city_name:    string;
    wikidata_qid: string;
    country_code: string;
    status:       'pending' | 'processing' | 'success' | 'failed';
    error:        string | null;
    city:         { name_zh_tw: string | null; name_en: string } | null;
    created_at:   string;
}

const countryCode  = ref('');
const cityName     = ref('');
const candidates   = ref<Candidate[]>([]);
const jobs         = ref<JobRecord[]>([]);
const isSearching  = ref(false);
const isSubmitting = ref(false);
const activeTab    = ref<'search' | 'jobs'>('search');
const searchError  = ref<string | null>(null);

let pollTimer: number | null = null;

function initCountryFromUrl() {
    const params = new URLSearchParams(window.location.search);
    countryCode.value = params.get('country') ?? '';
}

async function search() {
    if (!cityName.value || !countryCode.value) return;
    isSearching.value = true;
    searchError.value = null;
    candidates.value  = [];

    try {
        const params = new URLSearchParams({ city_name: cityName.value, country_code: countryCode.value });
        const res    = await fetch(`${api.cities.preview()}?${params}`);
        const json   = await res.json();
        candidates.value = json.data ?? [];
        if (candidates.value.length === 0) searchError.value = t('city_search.no_candidates');
    } catch {
        searchError.value = t('common.error_connection');
    } finally {
        isSearching.value = false;
    }
}

async function confirm(candidate: Candidate) {
    if (isSubmitting.value) return;
    isSubmitting.value = true;

    try {
        const res = await fetch(api.cities.search.index(), {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body:    JSON.stringify({
                city_name:    candidate.name_zh_tw ?? candidate.name_en ?? cityName.value,
                wikidata_qid: candidate.qid,
                country_code: countryCode.value,
            }),
            credentials: 'include',
        });
        const json = await res.json();
        if (res.ok) {
            candidates.value = [];
            cityName.value   = '';
            activeTab.value  = 'jobs';
            fetchJobs();
            startPolling(json.data.job_id);
        }
    } finally {
        isSubmitting.value = false;
    }
}

async function fetchJobs() {
    if (!countryCode.value) return;
    const params = new URLSearchParams({ country_code: countryCode.value });
    const res    = await fetch(`${api.cities.search.index()}?${params}`, { credentials: 'include' });
    const json   = await res.json();
    jobs.value   = json.data ?? [];
}

function startPolling(jobId: number) {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = window.setInterval(async () => {
        const res  = await fetch(api.cities.search.show(jobId), { credentials: 'include' });
        const json = await res.json();
        const job  = json.data;
        const idx  = jobs.value.findIndex(j => j.id === jobId);
        if (idx >= 0) jobs.value[idx] = job;
        else jobs.value.unshift(job);
        if (job.status === 'success' || job.status === 'failed') {
            clearInterval(pollTimer!);
            pollTimer = null;
        }
    }, 2500);
}

onMounted(() => {
    initCountryFromUrl();
    if (countryCode.value) fetchJobs();
});
</script>

<template>
    <AppLayout>
        <Head title="City Search" />

        <div class="mx-auto max-w-screen-md px-6 pb-24 pt-32 md:px-8">
            <div class="mb-8 pt-8">
                <span class="binary-label mb-2 block text-xs font-bold uppercase text-[var(--binary-primary)]">&gt; city_search</span>
                <h1 class="binary-display text-4xl font-black uppercase tracking-tight md:text-6xl">{{ t('city_search.title') }}</h1>
                <p class="mt-3 text-sm text-[var(--binary-text-muted)]">{{ t('city_search.subtitle') }}</p>
            </div>

            <!-- Not logged in -->
            <div v-if="!user" class="rounded-xl border border-[var(--binary-outline-variant)] p-8 text-center text-sm text-[var(--binary-outline)]">
                {{ t('city_search.login_required') }}
            </div>

            <!-- Not verified -->
            <div v-else-if="!user.email_verified_at" class="rounded-xl border border-[var(--binary-outline-variant)] p-8 text-center text-sm text-[var(--binary-outline)]">
                {{ t('city_search.verify_required') }}
            </div>

            <template v-else>
                <!-- Tabs -->
                <div class="mb-6 flex gap-4 border-b border-[var(--binary-outline-variant)]">
                    <button
                        class="binary-label pb-2 text-xs uppercase transition"
                        :class="activeTab === 'search' ? 'border-b-2 border-[var(--binary-primary)] text-[var(--binary-primary)]' : 'text-[var(--binary-outline)]'"
                        @click="activeTab = 'search'"
                    >{{ t('city_search.tab_search') }}</button>
                    <button
                        class="binary-label pb-2 text-xs uppercase transition"
                        :class="activeTab === 'jobs' ? 'border-b-2 border-[var(--binary-primary)] text-[var(--binary-primary)]' : 'text-[var(--binary-outline)]'"
                        @click="activeTab = 'jobs'; fetchJobs()"
                    >{{ t('city_search.tab_jobs') }}</button>
                </div>

                <!-- Search tab -->
                <div v-if="activeTab === 'search'">
                    <div class="mb-6 flex gap-3">
                        <input
                            v-model="countryCode"
                            type="text"
                            placeholder="TW"
                            maxlength="2"
                            class="w-20 rounded-lg border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-container)] px-3 py-2 font-mono text-sm uppercase text-[var(--binary-text)] placeholder:text-[var(--binary-outline)] focus:border-[var(--binary-primary)] focus:outline-none"
                        >
                        <input
                            v-model="cityName"
                            type="text"
                            :placeholder="t('city_search.placeholder')"
                            class="flex-1 rounded-lg border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-container)] px-4 py-2 text-sm text-[var(--binary-text)] placeholder:text-[var(--binary-outline)] focus:border-[var(--binary-primary)] focus:outline-none"
                            @keydown.enter="search"
                        >
                        <button
                            :disabled="isSearching || !cityName || !countryCode"
                            class="binary-label rounded-lg bg-[var(--binary-surface-container)] px-4 py-2 text-xs uppercase text-[var(--binary-primary)] transition hover:bg-[var(--binary-surface-high)] disabled:opacity-40"
                            @click="search"
                        >
                            {{ isSearching ? t('common.loading') : t('city_search.search') }}
                        </button>
                    </div>

                    <div v-if="searchError" class="mb-4 text-sm text-[var(--binary-tertiary)]">{{ searchError }}</div>

                    <!-- Candidates -->
                    <div v-if="candidates.length" class="space-y-3">
                        <div
                            v-for="c in candidates"
                            :key="c.qid"
                            class="flex items-start justify-between gap-4 rounded-xl border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-container)] px-5 py-4"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-[var(--binary-text)]">{{ c.name_zh_tw ?? c.name_en }}</p>
                                <p v-if="c.name_zh_tw && c.name_en" class="text-xs text-[var(--binary-outline)]">{{ c.name_en }}</p>
                                <p v-if="c.description" class="mt-1 text-xs text-[var(--binary-text-muted)]">{{ c.description }}</p>
                                <a :href="c.url" target="_blank" class="mt-1 inline-block binary-label text-[10px] uppercase text-[var(--binary-primary)] hover:underline">
                                    {{ c.qid }} ↗
                                </a>
                            </div>
                            <button
                                :disabled="isSubmitting"
                                class="binary-label flex-shrink-0 rounded-lg bg-[var(--binary-primary)]/10 px-3 py-1.5 text-[10px] uppercase text-[var(--binary-primary)] transition hover:bg-[var(--binary-primary)]/20 disabled:opacity-40"
                                @click="confirm(c)"
                            >{{ t('city_search.confirm') }}</button>
                        </div>
                    </div>
                </div>

                <!-- Jobs tab -->
                <div v-else>
                    <div v-if="jobs.length === 0" class="py-16 text-center text-sm text-[var(--binary-outline)]">{{ t('common.no_data') }}</div>
                    <div v-else class="space-y-3">
                        <div
                            v-for="job in jobs"
                            :key="job.id"
                            class="flex items-center gap-4 rounded-xl border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-container)] px-5 py-3"
                        >
                            <span
                                class="binary-label flex-shrink-0 rounded px-2 py-0.5 text-[10px] uppercase"
                                :class="{
                                    'bg-yellow-500/10 text-yellow-400':  job.status === 'pending' || job.status === 'processing',
                                    'bg-green-500/10 text-green-400':    job.status === 'success',
                                    'bg-red-500/10 text-red-400':        job.status === 'failed',
                                }"
                            >{{ job.status }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm text-[var(--binary-text)]">
                                    {{ job.city?.name_zh_tw ?? job.city?.name_en ?? job.city_name }}
                                </p>
                                <p class="binary-label text-[10px] text-[var(--binary-outline)]">{{ job.wikidata_qid }}</p>
                                <p v-if="job.error" class="text-xs text-red-400">{{ job.error }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>

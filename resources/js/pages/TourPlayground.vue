<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BookingTab from '@/components/tour/BookingTab.vue';
import ManagementTab from '@/components/tour/ManagementTab.vue';
import PassengerTab from '@/components/tour/PassengerTab.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { tourFetch } from '@/lib/tour-api';

type Stats = {
    passengers_count: number;
    bookings_count: number;
    tours_count: number;
};

const { t } = useI18n();

const activeTab = ref<'passengers' | 'booking' | 'management'>('passengers');

const stats = ref<Stats | null>(null);
const statsLoading = ref(false);

async function loadStats() {
    statsLoading.value = true;

    try {
        stats.value = await tourFetch<Stats>('/stats');
    } finally {
        statsLoading.value = false;
    }
}

onMounted(loadStats);
</script>

<template>
    <Head title="Tour Playground" />
    <AppLayout>
        <main class="pb-24">
            <section class="binary-section mx-auto max-w-screen-xl">
                <!-- Header -->
                <div class="binary-card-raised mb-6">
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <h1
                                class="binary-display text-3xl font-black tracking-tight uppercase"
                            >
                                Tour Playground
                            </h1>
                            <p
                                class="mt-1 text-sm text-[var(--binary-text-muted)]"
                            >
                                {{ t('tour_playground.subtitle') }}
                            </p>
                        </div>

                        <!-- Stat Cards -->
                        <div class="flex flex-wrap gap-3">
                            <template v-if="statsLoading">
                                <div
                                    v-for="i in 3"
                                    :key="i"
                                    class="h-16 w-28 animate-pulse rounded-none bg-[var(--binary-surface-container)] md:rounded-xl"
                                />
                            </template>
                            <template v-else-if="stats">
                                <div
                                    v-for="card in [
                                        {
                                            label: t(
                                                'tour_playground.stat_passengers',
                                            ),
                                            value: stats.passengers_count,
                                        },
                                        {
                                            label: t(
                                                'tour_playground.stat_bookings',
                                            ),
                                            value: stats.bookings_count,
                                        },
                                        {
                                            label: t(
                                                'tour_playground.stat_tours',
                                            ),
                                            value: stats.tours_count,
                                        },
                                    ]"
                                    :key="card.label"
                                    class="min-w-[6.5rem] rounded-none border border-[var(--binary-outline)]/20 bg-[var(--binary-surface-container)] px-4 py-3 text-center md:rounded-xl"
                                >
                                    <p
                                        class="binary-display text-2xl font-black text-[var(--binary-primary)]"
                                    >
                                        {{ card.value.toLocaleString() }}
                                    </p>
                                    <p
                                        class="mt-0.5 text-[10px] tracking-wide text-[var(--binary-text-muted)] uppercase"
                                    >
                                        {{ card.label }}
                                    </p>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Tab Navigation -->
                <div
                    class="mb-6 flex gap-1 border-b border-[var(--binary-outline)]/20 pb-0"
                >
                    <button
                        v-for="tab in [
                            {
                                key: 'passengers',
                                label: t('tour_playground.tab_passengers'),
                            },
                            {
                                key: 'booking',
                                label: t('tour_playground.tab_booking'),
                            },
                            {
                                key: 'management',
                                label: t('tour_playground.tab_management'),
                            },
                        ]"
                        :key="tab.key"
                        class="-mb-[2px] border-b-2 px-5 py-2.5 text-sm font-medium tracking-wide uppercase transition-colors"
                        :class="
                            activeTab === tab.key
                                ? 'border-[var(--binary-primary)] text-[var(--binary-primary)]'
                                : 'border-transparent text-[var(--binary-text-muted)] hover:text-[var(--binary-text)]'
                        "
                        @click="activeTab = tab.key as typeof activeTab.value"
                    >
                        {{ tab.label }}
                    </button>
                </div>

                <div v-show="activeTab === 'passengers'">
                    <PassengerTab :active="activeTab === 'passengers'" />
                </div>

                <div v-show="activeTab === 'booking'">
                    <BookingTab
                        :active="activeTab === 'booking'"
                        @stats-reload="loadStats"
                    />
                </div>

                <div v-show="activeTab === 'management'">
                    <ManagementTab :active="activeTab === 'management'" />
                </div>
            </section>
        </main>
    </AppLayout>
</template>

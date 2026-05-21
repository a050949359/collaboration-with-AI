<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { tourFetch, type Passenger } from '@/lib/tour-api';

const props = defineProps<{ active: boolean }>();

const { t } = useI18n();

const PASSENGER_FILTERS = [
    { value: '',               labelKey: 'tour_playground.passenger.filter_all' },
    { value: 'booker',         labelKey: 'tour_playground.passenger.filter_booker' },
    { value: 'companion_only', labelKey: 'tour_playground.passenger.filter_companion' },
    { value: 'no_booking',     labelKey: 'tour_playground.passenger.filter_no_booking' },
];

const passengerFilter = ref<string>('booker');
const passenger = ref<Passenger | null>(null);
const passengerLoading = ref(false);
const passengerError = ref('');

async function loadRandomPassenger() {
    passengerLoading.value = true;
    passengerError.value = '';
    passenger.value = null;
    try {
        const qs = passengerFilter.value ? `?filter=${passengerFilter.value}` : '';
        passenger.value = await tourFetch<Passenger>(`/passengers/random${qs}`);
    } catch (e) {
        passengerError.value = e instanceof Error ? e.message : t('tour_playground.passenger.load_failed');
    } finally {
        passengerLoading.value = false;
    }
}
</script>

<template>
    <div class="binary-card-raised rounded-2xl">

        <!-- Controls -->
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <button
                v-for="f in PASSENGER_FILTERS"
                :key="f.value"
                class="rounded-full border px-3 py-1 text-xs transition-colors"
                :class="passengerFilter === f.value
                    ? 'border-[var(--binary-primary)] bg-[var(--binary-primary)]/10 text-[var(--binary-primary)]'
                    : 'border-[var(--binary-outline)]/30 text-[var(--binary-text-muted)] hover:border-[var(--binary-outline)]'"
                @click="passengerFilter = f.value"
            >{{ t(f.labelKey) }}</button>
            <button class="binary-button ml-auto px-4 py-1.5 text-xs" :disabled="passengerLoading" @click="loadRandomPassenger">
                {{ passengerLoading ? t('common.loading') : t('tour_playground.passenger.random_pick') }}
            </button>
        </div>

        <!-- Empty state -->
        <p v-if="!passenger && !passengerLoading && !passengerError" class="py-10 text-center text-sm text-[var(--binary-text-muted)]">
            {{ t('tour_playground.passenger.empty_hint') }}
        </p>

        <p v-if="passengerLoading" class="py-10 text-center text-sm text-[var(--binary-text-muted)]">{{ t('common.loading') }}</p>
        <p v-else-if="passengerError" class="text-sm text-red-400">{{ passengerError }}</p>

        <!-- Passenger card -->
        <template v-else-if="passenger">
            <div class="rounded-xl border border-[var(--binary-outline)]/20 p-4">
                <div class="mb-4 flex items-start justify-between">
                    <div>
                        <p class="text-lg font-bold">{{ passenger.name }}</p>
                        <p class="text-xs text-[var(--binary-text-muted)]">{{ passenger.email }} ｜ {{ passenger.phone }}</p>
                    </div>
                    <span class="font-mono text-xs text-[var(--binary-text-muted)]">#{{ passenger.id }}</span>
                </div>

                <!-- Bookings (booker) -->
                <template v-if="passengerFilter === 'booker' && passenger.bookings?.length">
                    <p class="mb-2 text-[10px] uppercase text-[var(--binary-text-muted)]">{{ t('tour_playground.passenger.bookings_label', { n: passenger.bookings.length }) }}</p>
                    <div class="space-y-2">
                        <div
                            v-for="b in passenger.bookings"
                            :key="b.id"
                            class="rounded-lg bg-[var(--binary-surface-container)]/50 px-3 py-2 text-xs"
                        >
                            <div class="flex items-center justify-between">
                                <span class="font-mono">{{ b.booking_reference }}</span>
                                <span :class="{
                                    'text-yellow-400': b.status === 'pending',
                                    'text-green-400': b.status === 'confirmed',
                                    'text-red-400': b.status === 'cancelled',
                                }">{{ b.status }}</span>
                            </div>
                            <p class="mt-0.5 text-[var(--binary-text-muted)]">
                                {{ b.tour?.code }} ｜ {{ b.number_of_travelers }} {{ t('tour_playground.pax_unit') }} ｜ NT$ {{ Number(b.final_amount).toLocaleString() }}
                            </p>
                            <p class="mt-0.5 text-[var(--binary-text-muted)]">
                                {{ t('tour_playground.passenger.paid_prefix') }}NT$ {{ b.payments?.reduce((s, p) => s + Number(p.amount), 0).toLocaleString() ?? 0 }}
                                <span v-if="b.payments && b.payments.reduce((s, p) => s + Number(p.amount), 0) >= Number(b.final_amount)"
                                    class="ml-1 text-[var(--binary-primary)]">{{ t('tour_playground.passenger.paid_full') }}</span>
                            </p>
                        </div>
                    </div>
                </template>

                <!-- Companion of -->
                <template v-else-if="passengerFilter === 'companion_only' && passenger.companion_of?.length">
                    <p class="mb-2 text-[10px] uppercase text-[var(--binary-text-muted)]">{{ t('tour_playground.passenger.companions_label', { n: passenger.companion_of.length }) }}</p>
                    <div class="flex flex-wrap gap-1.5">
                        <span
                            v-for="b in passenger.companion_of"
                            :key="b.id"
                            class="rounded bg-[var(--binary-outline)]/10 px-2 py-1 text-xs"
                        >{{ b.tour?.code ?? `#${b.tour_id}` }}</span>
                    </div>
                </template>

                <!-- No booking -->
                <template v-else-if="passengerFilter === 'no_booking'">
                    <p class="text-xs text-[var(--binary-text-muted)]">{{ t('tour_playground.passenger.no_records') }}</p>
                </template>

                <!-- All -->
                <template v-else-if="passengerFilter === ''">
                    <template v-if="passenger.bookings?.length">
                        <p class="mb-2 text-[10px] uppercase text-[var(--binary-text-muted)]">{{ t('tour_playground.passenger.bookings_label', { n: passenger.bookings.length }) }}</p>
                        <div class="mb-4 space-y-2">
                            <div
                                v-for="b in passenger.bookings"
                                :key="b.id"
                                class="rounded-lg bg-[var(--binary-surface-container)]/50 px-3 py-2 text-xs"
                            >
                                <div class="flex items-center justify-between">
                                    <span class="font-mono">{{ b.booking_reference }}</span>
                                    <span :class="{
                                        'text-yellow-400': b.status === 'pending',
                                        'text-green-400': b.status === 'confirmed',
                                        'text-red-400': b.status === 'cancelled',
                                    }">{{ b.status }}</span>
                                </div>
                                <p class="mt-0.5 text-[var(--binary-text-muted)]">
                                    {{ b.tour?.code }} ｜ {{ b.number_of_travelers }} {{ t('tour_playground.pax_unit') }} ｜ NT$ {{ Number(b.final_amount).toLocaleString() }}
                                </p>
                                <p class="mt-0.5 text-[var(--binary-text-muted)]">
                                    {{ t('tour_playground.passenger.paid_prefix') }}NT$ {{ b.payments?.reduce((s, p) => s + Number(p.amount), 0).toLocaleString() ?? 0 }}
                                    <span v-if="b.payments && b.payments.reduce((s, p) => s + Number(p.amount), 0) >= Number(b.final_amount)"
                                        class="ml-1 text-[var(--binary-primary)]">{{ t('tour_playground.passenger.paid_full') }}</span>
                                </p>
                            </div>
                        </div>
                    </template>

                    <template v-if="passenger.companion_of?.length">
                        <p class="mb-2 text-[10px] uppercase text-[var(--binary-text-muted)]">{{ t('tour_playground.passenger.companions_label', { n: passenger.companion_of.length }) }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            <span
                                v-for="b in passenger.companion_of"
                                :key="b.id"
                                class="rounded bg-[var(--binary-outline)]/10 px-2 py-1 text-xs"
                            >{{ b.tour?.code ?? `#${b.tour_id}` }}</span>
                        </div>
                    </template>

                    <p v-if="!passenger.bookings?.length && !passenger.companion_of?.length"
                        class="text-xs text-[var(--binary-text-muted)]">{{ t('tour_playground.passenger.no_records') }}</p>
                </template>
            </div>
        </template>

    </div>
</template>

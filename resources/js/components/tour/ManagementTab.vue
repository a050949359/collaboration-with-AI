<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { api } from '@/lib/routes';
import {
    tourFetch, safeParseParams,
    type Tour, type Booking, type ExportTask, type Airport, type TourFlight, type TourHotel,
} from '@/lib/tour-api';

const props = defineProps<{ active: boolean }>();
const page = usePage<{ cabinClasses: string[]; roomTypes: string[] }>();
const cabinClasses = computed(() => page.props.cabinClasses);
const roomTypes    = computed(() => page.props.roomTypes);

const { t } = useI18n();

const mgmtTab = ref<'bookings' | 'exports' | 'tours'>('bookings');

// ── Bookings accordion ────────────────────────────────────

const expandedTourId = ref<number | null>(null);
const tourBookingsCache = ref<Record<number, Booking[]>>({});
const loadingTourId = ref<number | null>(null);

async function loadTourBookings(tourId: number) {
    if (tourBookingsCache.value[tourId]) return;
    loadingTourId.value = tourId;
    try {
        tourBookingsCache.value[tourId] = await tourFetch<Booking[]>(`/bookings?tour_id=${tourId}`);
    } finally {
        loadingTourId.value = null;
    }
}

function toggleTourExpand(tourId: number) {
    if (expandedTourId.value === tourId) { expandedTourId.value = null; return; }
    expandedTourId.value = tourId;
    loadTourBookings(tourId);
}

// ── Exports ───────────────────────────────────────────────

const exports_ = ref<ExportTask[]>([]);
const exportsLoading = ref(false);
const exportingId = ref<number | null>(null);
const exportError = ref('');
const pollingMap = new Map<number, ReturnType<typeof setInterval>>();

function stopPolling(id: number) {
    clearInterval(pollingMap.get(id)!);
    pollingMap.delete(id);
}

function stopAllPolling() {
    pollingMap.forEach((_, id) => stopPolling(id));
}

function startPolling(id: number) {
    if (pollingMap.has(id)) return;
    const timer = setInterval(async () => {
        const data = await tourFetch<{ status: string }>(`/exports/${id}/status`).catch(() => null);
        if (!data) return stopPolling(id);
        const task = exports_.value.find(e => e.id === id);
        if (task) task.status = data.status;
        if (data.status === 'completed' || data.status === 'failed') {
            stopPolling(id);
            if (data.status === 'completed') loadExports();
        }
    }, 2000);
    pollingMap.set(id, timer);
}

async function loadExports() {
    exportsLoading.value = true;
    try {
        exports_.value = await tourFetch<ExportTask[]>('/exports');
        if (mgmtTab.value === 'exports') {
            exports_.value
                .filter(e => e.status === 'pending' || e.status === 'processing')
                .forEach(e => startPolling(e.id));
        }
    } finally {
        exportsLoading.value = false;
    }
}

async function triggerExport(tour: Tour) {
    if (exportingId.value !== null) return;
    exportingId.value = tour.id;
    exportError.value = '';
    try {
        await tourFetch<{ id: number }>('/exports', {
            method: 'POST',
            body: JSON.stringify({ tour_id: tour.id, tour_code: tour.code }),
        });
        mgmtTab.value = 'exports';
        await loadExports();
    } catch (e) {
        exportError.value = e instanceof Error ? e.message : t('tour_playground.mgmt.export_failed');
    } finally {
        exportingId.value = null;
    }
}

function openExportDownload(id: number) {
    window.open(api.tour.exportDownload(id), '_blank');
}

// ── Tours ─────────────────────────────────────────────────

const mgmtTours = ref<Tour[]>([]);
const mgmtToursLoading = ref(false);
const tourFormMode = ref<'create' | 'edit'>('create');
const editingTour = ref<Tour | null>(null);
const tourForm = ref({
    name: '', type: 'group', duration: 7,
    departure_date: '', return_date: '',
    selling_price: '', min_pax: 10, max_pax: 30, remarks: '',
});
const tourFormLoading = ref(false);
const tourFormError = ref('');
const tourFormSuccess = ref('');
const showTourForm = ref(false);
const tourEditTab = ref<'info' | 'flights' | 'hotels'>('info');

const canAddFlight = computed(() => editingTourFlights.value.length < 2);
const canAddHotel = computed(() => {
    if (!editingTour.value) return false;
    const covered = editingTourHotels.value.reduce((s, h) => s + h.nights, 0);
    return covered < editingTour.value.duration;
});

async function loadMgmtTours() {
    mgmtToursLoading.value = true;
    try { mgmtTours.value = await tourFetch<Tour[]>('/tours'); }
    finally { mgmtToursLoading.value = false; }
}

function openCreateForm() {
    tourFormMode.value = 'create';
    editingTour.value = null;
    tourForm.value = { name: '', type: 'group', duration: 7, departure_date: '', return_date: '', selling_price: '', min_pax: 10, max_pax: 30, remarks: '' };
    tourFormError.value = '';
    tourFormSuccess.value = '';
    editingTourFlights.value = [];
    editingTourHotels.value = [];
    showTourForm.value = true;
}

function editTour(tour: Tour) {
    tourFormMode.value = 'edit';
    editingTour.value = tour;
    tourForm.value = {
        name: tour.name, type: tour.type,
        duration: tour.duration,
        departure_date: tour.departure_date, return_date: tour.return_date,
        selling_price: String(tour.selling_price),
        min_pax: tour.min_pax, max_pax: tour.max_pax,
        remarks: tour.remarks ?? '',
    };
    tourFormError.value = '';
    tourFormSuccess.value = '';
    mgmtTab.value = 'tours';
    tourEditTab.value = 'info';
    showTourForm.value = true;
    editingTourFlights.value = [];
    editingTourHotels.value = [];
    loadEditingTourFlights();
    loadEditingTourHotels();
}

function cancelTourForm() {
    showTourForm.value = false;
    tourFormMode.value = 'create';
    editingTour.value = null;
    tourForm.value = { name: '', type: 'group', duration: 7, departure_date: '', return_date: '', selling_price: '', min_pax: 10, max_pax: 30, remarks: '' };
    tourFormError.value = '';
    tourFormSuccess.value = '';
    editingTourFlights.value = [];
    editingTourHotels.value = [];
    flightForm.value = { flight_number: '', cabin_class: cabinClasses.value[0], origin_airport_id: 0, destination_airport_id: 0, departure_time: '', arrival_time: '', cost_price: 0, remarks: '' };
    hotelForm.value = { hotel_name: '', check_in_date: '', check_out_date: '', room_type: roomTypes.value[0], number_of_rooms: 1, cost_price_per_night: 0, remarks: '' };
    originQuery.value = ''; destQuery.value = '';
}

async function submitTour() {
    tourFormLoading.value = true;
    tourFormError.value = '';
    tourFormSuccess.value = '';
    try {
        const payload = { ...tourForm.value, selling_price: parseFloat(tourForm.value.selling_price) };
        if (tourFormMode.value === 'create') {
            await tourFetch('/tours', { method: 'POST', body: JSON.stringify(payload) });
            tourFormSuccess.value = t('tour_playground.mgmt.tour_create_success');
        } else if (editingTour.value) {
            await tourFetch(`/tours/${editingTour.value.id}`, { method: 'PUT', body: JSON.stringify(payload) });
            tourFormSuccess.value = t('tour_playground.mgmt.tour_update_success');
        }
        cancelTourForm();
        await loadMgmtTours();
    } catch (e) {
        tourFormError.value = e instanceof Error ? e.message : t('tour_playground.mgmt.op_failed');
    } finally {
        tourFormLoading.value = false;
    }
}

// ── Flights ───────────────────────────────────────────────

const editingTourFlights = ref<TourFlight[]>([]);
const flightsLoading = ref(false);
const flightForm = ref({ flight_number: '', cabin_class: cabinClasses.value[0], origin_airport_id: 0, destination_airport_id: 0, departure_time: '', arrival_time: '', cost_price: 0, remarks: '' });
const flightFormError = ref('');
const flightSubmitting = ref(false);

const originQuery = ref('');
const originResults = ref<Airport[]>([]);
const originOpen = ref(false);
const destQuery = ref('');
const destResults = ref<Airport[]>([]);
const destOpen = ref(false);
let originTimer: ReturnType<typeof setTimeout> | null = null;
let destTimer: ReturnType<typeof setTimeout> | null = null;

async function searchAirports(q: string): Promise<Airport[]> {
    if (q.length < 2) return [];
    const res = await fetch(`/api/v1/airports?search=${encodeURIComponent(q)}&limit=8`, { headers: { Accept: 'application/json' } });
    if (!res.ok) return [];
    const data = await res.json();
    return Array.isArray(data) ? data : (data.data ?? []);
}

function onOriginInput() {
    originOpen.value = true;
    flightForm.value.origin_airport_id = 0;
    if (originTimer) clearTimeout(originTimer);
    originTimer = setTimeout(async () => { originResults.value = await searchAirports(originQuery.value); }, 300);
}

function selectOrigin(a: Airport) {
    flightForm.value.origin_airport_id = a.id;
    originQuery.value = a.iata_code ? `${a.iata_code} — ${a.name}` : a.name;
    originOpen.value = false;
}

function onDestInput() {
    destOpen.value = true;
    flightForm.value.destination_airport_id = 0;
    if (destTimer) clearTimeout(destTimer);
    destTimer = setTimeout(async () => { destResults.value = await searchAirports(destQuery.value); }, 300);
}

function selectDest(a: Airport) {
    flightForm.value.destination_airport_id = a.id;
    destQuery.value = a.iata_code ? `${a.iata_code} — ${a.name}` : a.name;
    destOpen.value = false;
}

async function loadEditingTourFlights() {
    if (!editingTour.value) return;
    flightsLoading.value = true;
    try { editingTourFlights.value = await tourFetch<TourFlight[]>(`/${editingTour.value.id}/flights`); }
    finally { flightsLoading.value = false; }
}

async function addFlight() {
    if (!editingTour.value || flightSubmitting.value) return;
    flightSubmitting.value = true;
    flightFormError.value = '';
    try {
        await tourFetch(`/${editingTour.value.id}/flights`, { method: 'POST', body: JSON.stringify(flightForm.value) });
        flightForm.value = { flight_number: '', cabin_class: cabinClasses.value[0], origin_airport_id: 0, destination_airport_id: 0, departure_time: '', arrival_time: '', cost_price: 0, remarks: '' };
        originQuery.value = ''; destQuery.value = '';
        await loadEditingTourFlights();
    } catch (e) {
        flightFormError.value = e instanceof Error ? e.message : t('tour_playground.mgmt.add_failed');
    } finally {
        flightSubmitting.value = false;
    }
}

async function deleteFlight(flightId: number) {
    if (!editingTour.value) return;
    await tourFetch(`/${editingTour.value.id}/flights/${flightId}`, { method: 'DELETE' }).catch(() => null);
    editingTourFlights.value = editingTourFlights.value.filter(f => f.id !== flightId);
}

// ── Hotels ────────────────────────────────────────────────

const editingTourHotels = ref<TourHotel[]>([]);
const hotelsLoading = ref(false);
const hotelForm = ref({ hotel_name: '', check_in_date: '', check_out_date: '', room_type: roomTypes.value[0], number_of_rooms: 1, cost_price_per_night: 0, remarks: '' });
const hotelFormError = ref('');
const hotelSubmitting = ref(false);

async function loadEditingTourHotels() {
    if (!editingTour.value) return;
    hotelsLoading.value = true;
    try { editingTourHotels.value = await tourFetch<TourHotel[]>(`/${editingTour.value.id}/hotels`); }
    finally { hotelsLoading.value = false; }
}

async function addHotel() {
    if (!editingTour.value || hotelSubmitting.value) return;
    hotelSubmitting.value = true;
    hotelFormError.value = '';
    try {
        await tourFetch(`/${editingTour.value.id}/hotels`, { method: 'POST', body: JSON.stringify(hotelForm.value) });
        hotelForm.value = { hotel_name: '', check_in_date: '', check_out_date: '', room_type: roomTypes.value[0], number_of_rooms: 1, cost_price_per_night: 0, remarks: '' };
        await loadEditingTourHotels();
    } catch (e) {
        hotelFormError.value = e instanceof Error ? e.message : t('tour_playground.mgmt.add_failed');
    } finally {
        hotelSubmitting.value = false;
    }
}

async function deleteHotel(hotelId: number) {
    if (!editingTour.value) return;
    await tourFetch(`/${editingTour.value.id}/hotels/${hotelId}`, { method: 'DELETE' }).catch(() => null);
    editingTourHotels.value = editingTourHotels.value.filter(h => h.id !== hotelId);
}

// ── Lifecycle ─────────────────────────────────────────────

watch(() => props.active, (isActive) => {
    if (isActive) {
        if (!exports_.value.length) loadExports();
        if (!mgmtTours.value.length) loadMgmtTours();
    } else {
        stopAllPolling();
    }
});

watch(mgmtTab, (tab) => {
    if (tab === 'exports') {
        exports_.value
            .filter(e => e.status === 'pending' || e.status === 'processing')
            .forEach(e => startPolling(e.id));
    } else {
        stopAllPolling();
    }
});
</script>

<template>
    <div>
        <!-- Sub-tabs -->
        <div class="mb-4 flex gap-1">
            <button
                v-for="tab in [
                    { key: 'bookings', label: t('tour_playground.mgmt.sub_bookings') },
                    { key: 'exports',  label: t('tour_playground.mgmt.sub_exports') },
                    { key: 'tours',    label: t('tour_playground.mgmt.sub_tours') },
                ]"
                :key="tab.key"
                class="rounded-md px-3 py-1.5 text-xs font-medium uppercase transition-colors"
                :class="mgmtTab === tab.key
                    ? 'bg-[var(--binary-primary)]/20 text-[var(--binary-primary)]'
                    : 'text-[var(--binary-text-muted)] hover:text-[var(--binary-text)]'"
                @click="mgmtTab = tab.key as any"
            >{{ tab.label }}</button>
        </div>

        <!-- ─── Bookings ─────────────────────────── -->
        <div v-show="mgmtTab === 'bookings'" class="binary-card-raised rounded-2xl">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-sm font-bold uppercase tracking-wide">{{ t('tour_playground.mgmt.bookings_title') }}</h2>
                <button class="binary-ghost-button px-3 py-1 text-xs" @click="loadMgmtTours">{{ t('tour_playground.mgmt.refresh') }}</button>
            </div>
            <p v-if="exportError" class="mb-3 text-xs text-red-400">{{ exportError }}</p>
            <p v-if="mgmtToursLoading" class="py-6 text-center text-sm text-[var(--binary-text-muted)]">{{ t('common.loading') }}</p>
            <div v-else class="space-y-1.5">
                <div
                    v-for="tour in mgmtTours"
                    :key="tour.id"
                    class="overflow-hidden rounded-lg border border-[var(--binary-outline)]/20"
                >
                    <div
                        class="flex cursor-pointer items-center gap-3 px-3 py-2.5 hover:bg-[var(--binary-primary)]/5"
                        @click="toggleTourExpand(tour.id)"
                    >
                        <span class="text-[10px] text-[var(--binary-text-muted)] w-2 shrink-0">
                            {{ expandedTourId === tour.id ? '▲' : '▼' }}
                        </span>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-1.5">
                                <span class="font-mono text-xs text-[var(--binary-text-muted)]">{{ tour.code }}</span>
                                <span class="rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                                    :class="tour.is_formed ? 'bg-green-500/15 text-green-400' : 'bg-yellow-500/15 text-yellow-400'">
                                    {{ tour.is_formed ? t('tour_playground.mgmt.formed') : t('tour_playground.mgmt.not_formed') }}
                                </span>
                                <span v-if="(tour.booked_pax ?? 0) > tour.max_pax"
                                    class="rounded-full bg-red-500/15 px-1.5 py-0.5 text-[10px] font-medium text-red-400">
                                    {{ t('tour_playground.mgmt.overbooked', { n: (tour.booked_pax ?? 0) - tour.max_pax }) }}
                                </span>
                            </div>
                            <p class="truncate text-xs font-medium">{{ tour.name }}</p>
                        </div>
                        <span class="shrink-0 text-xs text-[var(--binary-text-muted)]">
                            {{ tour.booked_pax ?? 0 }} / {{ tour.max_pax }} {{ t('tour_playground.pax_unit') }}
                        </span>
                        <button
                            class="binary-ghost-button shrink-0 px-2 py-0.5 text-[10px]"
                            :disabled="exportingId === tour.id"
                            @click.stop="triggerExport(tour)"
                        >{{ exportingId === tour.id ? t('tour_playground.mgmt.exporting') : t('tour_playground.mgmt.export_btn') }}</button>
                    </div>

                    <div v-if="expandedTourId === tour.id"
                        class="border-t border-[var(--binary-outline)]/20 bg-[var(--binary-surface-container)]/30 px-3 py-2"
                    >
                        <p v-if="loadingTourId === tour.id" class="py-3 text-center text-xs text-[var(--binary-text-muted)]">{{ t('common.loading') }}</p>
                        <template v-else-if="tourBookingsCache[tour.id]">
                            <p v-if="!tourBookingsCache[tour.id].length" class="py-3 text-center text-xs text-[var(--binary-text-muted)]">{{ t('tour_playground.mgmt.no_bookings') }}</p>
                            <table v-else class="w-full">
                                <thead>
                                    <tr class="text-left text-[10px] uppercase text-[var(--binary-text-muted)]">
                                        <th class="pb-1.5 pr-3">{{ t('tour_playground.mgmt.col_booking_ref') }}</th>
                                        <th class="pb-1.5 pr-3">{{ t('tour_playground.mgmt.col_passenger') }}</th>
                                        <th class="pb-1.5 pr-3">{{ t('tour_playground.mgmt.col_travelers') }}</th>
                                        <th class="pb-1.5 pr-3">{{ t('tour_playground.mgmt.col_status') }}</th>
                                        <th class="pb-1.5">{{ t('tour_playground.mgmt.col_due') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="b in tourBookingsCache[tour.id]" :key="b.id"
                                        class="border-t border-[var(--binary-outline)]/10 text-xs"
                                    >
                                        <td class="py-1.5 pr-3 font-mono">{{ b.booking_reference }}</td>
                                        <td class="py-1.5 pr-3">{{ b.passenger?.name ?? '-' }}</td>
                                        <td class="py-1.5 pr-3">{{ b.number_of_travelers }}</td>
                                        <td class="py-1.5 pr-3">
                                            <span class="rounded-full px-1.5 py-0.5 text-[10px] font-medium uppercase"
                                                :class="{
                                                    'bg-yellow-500/20 text-yellow-400': b.status === 'pending',
                                                    'bg-green-500/20 text-green-400': b.status === 'confirmed',
                                                    'bg-red-500/20 text-red-400': b.status === 'cancelled' || b.status === 'refunded',
                                                }">{{ b.status }}</span>
                                        </td>
                                        <td class="py-1.5">NT$ {{ Number(b.final_amount).toLocaleString() }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- ─── Exports ──────────────────────────── -->
        <div v-show="mgmtTab === 'exports'" class="space-y-4">
            <div class="binary-card-raised rounded-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-sm font-bold uppercase tracking-wide">{{ t('tour_playground.mgmt.exports_title') }}</h2>
                    <button class="binary-ghost-button px-3 py-1 text-xs" @click="loadExports">{{ t('tour_playground.mgmt.refresh') }}</button>
                </div>
                <p v-if="exportsLoading" class="py-4 text-center text-sm text-[var(--binary-text-muted)]">{{ t('common.loading') }}</p>
                <div v-else class="space-y-2">
                    <p v-if="!exports_.length" class="py-4 text-center text-sm text-[var(--binary-text-muted)]">{{ t('tour_playground.mgmt.no_exports') }}</p>
                    <div
                        v-for="e in exports_"
                        :key="e.id"
                        class="flex items-center justify-between rounded-lg border border-[var(--binary-outline)]/20 px-3 py-2.5"
                    >
                        <div>
                            <p class="font-mono text-xs">{{ safeParseParams(e.params).tour_code ?? '-' }}</p>
                            <p class="mt-0.5 text-[10px] text-[var(--binary-text-muted)]">{{ e.created_at }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-medium uppercase"
                                :class="{
                                    'bg-yellow-500/20 text-yellow-400': e.status === 'pending',
                                    'bg-blue-500/20 text-blue-400': e.status === 'processing',
                                    'bg-green-500/20 text-green-400': e.status === 'completed',
                                    'bg-red-500/20 text-red-400': e.status === 'failed',
                                }"
                            >{{ e.status }}</span>
                            <button
                                v-if="e.status === 'completed'"
                                class="binary-button px-3 py-1 text-xs"
                                @click="openExportDownload(e.id)"
                            >{{ t('tour_playground.mgmt.download') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ─── Tours ────────────────────────────── -->
        <div v-show="mgmtTab === 'tours'" class="space-y-4">

            <!-- List -->
            <div v-if="!showTourForm" class="binary-card-raised rounded-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-sm font-bold uppercase tracking-wide">{{ t('tour_playground.mgmt.tours_title') }}</h2>
                    <div class="flex items-center gap-2">
                        <button class="binary-ghost-button px-3 py-1 text-xs" @click="loadMgmtTours">{{ t('tour_playground.mgmt.refresh') }}</button>
                        <button class="binary-button px-3 py-1 text-xs" @click="openCreateForm">{{ t('tour_playground.mgmt.add_tour') }}</button>
                    </div>
                </div>
                <p v-if="mgmtToursLoading" class="py-4 text-center text-sm text-[var(--binary-text-muted)]">{{ t('common.loading') }}</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[var(--binary-outline)]/20 text-left text-[10px] uppercase text-[var(--binary-text-muted)]">
                                <th class="pb-2 pr-4">Code</th>
                                <th class="pb-2 pr-4">{{ t('tour_playground.mgmt.col_name') }}</th>
                                <th class="pb-2 pr-4">{{ t('tour_playground.mgmt.col_depart') }}</th>
                                <th class="pb-2 pr-4">{{ t('tour_playground.mgmt.col_price') }}</th>
                                <th class="pb-2 pr-4">{{ t('tour_playground.mgmt.col_pax_limit') }}</th>
                                <th class="pb-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="tour in mgmtTours"
                                :key="tour.id"
                                class="border-b border-[var(--binary-outline)]/10 transition-colors"
                                :class="editingTour?.id === tour.id && showTourForm
                                    ? 'bg-[var(--binary-primary)]/10'
                                    : 'hover:bg-[var(--binary-primary)]/5'"
                            >
                                <td class="py-2 pr-4 font-mono text-xs">{{ tour.code }}</td>
                                <td class="py-2 pr-4 text-xs">{{ tour.name }}</td>
                                <td class="py-2 pr-4 text-xs text-[var(--binary-text-muted)]">{{ tour.departure_date }}</td>
                                <td class="py-2 pr-4 text-xs">NT$ {{ Number(tour.selling_price).toLocaleString() }}</td>
                                <td class="py-2 pr-4 text-xs text-[var(--binary-text-muted)]">{{ tour.min_pax }} – {{ tour.max_pax }}</td>
                                <td class="py-2 text-right">
                                    <button
                                        class="binary-ghost-button px-2 py-0.5 text-xs"
                                        :class="editingTour?.id === tour.id && showTourForm ? 'text-[var(--binary-primary)]' : ''"
                                        @click="editTour(tour)"
                                    >{{ t('tour_playground.mgmt.edit') }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Create / Edit Panel -->
            <div v-if="showTourForm" class="binary-card-raised rounded-2xl">

                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-bold uppercase tracking-wide">
                            {{ tourFormMode === 'create' ? t('tour_playground.mgmt.create_title') : editingTour?.name }}
                        </h2>
                        <p v-if="tourFormMode === 'edit'" class="mt-0.5 font-mono text-xs text-[var(--binary-text-muted)]">
                            {{ editingTour?.code }}
                        </p>
                    </div>
                    <button class="binary-ghost-button px-3 py-1 text-xs" @click="cancelTourForm">{{ t('tour_playground.mgmt.cancel') }}</button>
                </div>

                <!-- Sub-tabs (edit mode only) -->
                <div v-if="tourFormMode === 'edit'" class="mb-5 flex border-b border-[var(--binary-outline)]/20">
                    <button
                        v-for="tab in [
                            { key: 'info',    label: t('tour_playground.mgmt.edit_tab_info') },
                            { key: 'flights', label: t('tour_playground.mgmt.edit_tab_flights') },
                            { key: 'hotels',  label: t('tour_playground.mgmt.edit_tab_hotels') },
                        ]"
                        :key="tab.key"
                        class="px-4 py-2 text-xs font-medium uppercase tracking-wide border-b-2 -mb-[2px] transition-colors"
                        :class="tourEditTab === tab.key
                            ? 'border-[var(--binary-primary)] text-[var(--binary-primary)]'
                            : 'border-transparent text-[var(--binary-text-muted)] hover:text-[var(--binary-text)]'"
                        @click="tourEditTab = (tab.key as any)"
                    >{{ tab.label }}</button>
                </div>

                <!-- 基本資料 -->
                <div v-show="tourFormMode === 'create' || tourEditTab === 'info'">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="binary-label mb-1 block text-[10px] uppercase">{{ t('tour_playground.mgmt.form_name') }}</label>
                            <input v-model="tourForm.name" type="text" :placeholder="t('tour_playground.mgmt.placeholder_tour_name')" class="binary-input w-full" />
                        </div>
                        <div>
                            <label class="binary-label mb-1 block text-[10px] uppercase">{{ t('tour_playground.mgmt.form_type') }}</label>
                            <select v-model="tourForm.type" class="binary-input w-full">
                                <option value="group">Group</option>
                                <option value="fit">FIT</option>
                            </select>
                        </div>
                        <div>
                            <label class="binary-label mb-1 block text-[10px] uppercase">{{ t('tour_playground.mgmt.form_duration') }}</label>
                            <input v-model.number="tourForm.duration" type="number" min="1" class="binary-input w-full" />
                        </div>
                        <div>
                            <label class="binary-label mb-1 block text-[10px] uppercase">{{ t('tour_playground.mgmt.form_depart') }}</label>
                            <input v-model="tourForm.departure_date" type="date" class="binary-input w-full" />
                        </div>
                        <div>
                            <label class="binary-label mb-1 block text-[10px] uppercase">{{ t('tour_playground.mgmt.form_return') }}</label>
                            <input v-model="tourForm.return_date" type="date" class="binary-input w-full" />
                        </div>
                        <div>
                            <label class="binary-label mb-1 block text-[10px] uppercase">{{ t('tour_playground.mgmt.form_price') }}</label>
                            <input v-model="tourForm.selling_price" type="number" min="0" class="binary-input w-full" />
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="binary-label mb-1 block text-[10px] uppercase">{{ t('tour_playground.mgmt.form_min_pax') }}</label>
                                <input v-model.number="tourForm.min_pax" type="number" min="1" class="binary-input w-full" />
                            </div>
                            <div>
                                <label class="binary-label mb-1 block text-[10px] uppercase">{{ t('tour_playground.mgmt.form_max_pax') }}</label>
                                <input v-model.number="tourForm.max_pax" type="number" min="1" class="binary-input w-full" />
                            </div>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="binary-label mb-1 block text-[10px] uppercase">{{ t('tour_playground.mgmt.form_remarks') }}</label>
                            <textarea v-model="tourForm.remarks" rows="2" class="binary-input w-full resize-none" />
                        </div>
                    </div>
                    <div class="mt-4 flex items-center gap-3">
                        <button class="binary-button" :disabled="tourFormLoading" @click="submitTour">
                            {{ tourFormLoading ? t('tour_playground.mgmt.processing') : (tourFormMode === 'create' ? t('tour_playground.mgmt.create') : t('tour_playground.mgmt.update')) }}
                        </button>
                        <p v-if="tourFormError" class="text-xs text-red-400">{{ tourFormError }}</p>
                        <p v-if="tourFormSuccess" class="text-xs text-[var(--binary-primary)]">{{ tourFormSuccess }}</p>
                    </div>
                </div>

                <!-- 航班 -->
                <div v-show="tourFormMode === 'edit' && tourEditTab === 'flights'">
                    <p v-if="flightsLoading" class="mb-3 text-xs text-[var(--binary-text-muted)]">{{ t('common.loading') }}</p>
                    <div v-else-if="editingTourFlights.length" class="mb-4 space-y-1.5">
                        <div
                            v-for="f in editingTourFlights"
                            :key="f.id"
                            class="flex items-center justify-between rounded-lg border border-[var(--binary-outline)]/20 px-3 py-2 text-xs"
                        >
                            <div>
                                <span class="font-mono font-medium">{{ f.flight_number }}</span>
                                <span class="ml-2 text-[var(--binary-text-muted)]">{{ f.cabin_class }}</span>
                                <p class="mt-0.5 text-[var(--binary-text-muted)]">{{ f.departure_time }} → {{ f.arrival_time }}</p>
                            </div>
                            <button class="binary-ghost-button px-2 py-0.5 text-[10px] text-red-400 hover:text-red-300" @click="deleteFlight(f.id)">{{ t('tour_playground.mgmt.delete') }}</button>
                        </div>
                    </div>
                    <p v-else-if="!flightsLoading" class="mb-4 text-xs text-[var(--binary-text-muted)]">{{ t('tour_playground.mgmt.no_flights') }}</p>

                    <template v-if="canAddFlight">
                        <p class="mb-3 text-[10px] uppercase tracking-wide text-[var(--binary-text-muted)]">{{ t('tour_playground.mgmt.add_flight_title') }}</p>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <div>
                                <label class="binary-label mb-1 block text-[10px] uppercase">{{ t('tour_playground.mgmt.label_flight_no') }}</label>
                                <input v-model="flightForm.flight_number" type="text" placeholder="CI101" class="binary-input w-full" />
                            </div>
                            <div>
                                <label class="binary-label mb-1 block text-[10px] uppercase">{{ t('tour_playground.mgmt.label_cabin') }}</label>
                                <select v-model="flightForm.cabin_class" class="binary-input w-full">
                                    <option v-for="v in cabinClasses" :key="v" :value="v">{{ t(`tour_playground.mgmt.cabin_${v}`) }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="binary-label mb-1 block text-[10px] uppercase">{{ t('tour_playground.mgmt.label_cost') }}</label>
                                <input v-model.number="flightForm.cost_price" type="number" min="0" class="binary-input w-full" />
                            </div>
                            <div class="relative">
                                <label class="binary-label mb-1 block text-[10px] uppercase">{{ t('tour_playground.mgmt.label_origin') }}</label>
                                <input v-model="originQuery" type="text" :placeholder="t('tour_playground.mgmt.search_airport')" class="binary-input w-full"
                                    @input="onOriginInput" @blur="setTimeout(() => originOpen = false, 150)" />
                                <ul v-if="originOpen && originResults.length"
                                    class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-[var(--binary-outline)]/30 bg-[var(--binary-surface)] shadow-lg"
                                >
                                    <li v-for="a in originResults" :key="a.id"
                                        class="cursor-pointer px-3 py-2 text-xs hover:bg-[var(--binary-primary)]/10"
                                        @mousedown.prevent="selectOrigin(a)"
                                    >
                                        <span class="font-mono font-medium">{{ a.iata_code ?? a.ident }}</span>
                                        <span class="ml-1.5 text-[var(--binary-text-muted)]">{{ a.name }}</span>
                                    </li>
                                </ul>
                            </div>
                            <div class="relative">
                                <label class="binary-label mb-1 block text-[10px] uppercase">{{ t('tour_playground.mgmt.label_dest') }}</label>
                                <input v-model="destQuery" type="text" :placeholder="t('tour_playground.mgmt.search_airport')" class="binary-input w-full"
                                    @input="onDestInput" @blur="setTimeout(() => destOpen = false, 150)" />
                                <ul v-if="destOpen && destResults.length"
                                    class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-[var(--binary-outline)]/30 bg-[var(--binary-surface)] shadow-lg"
                                >
                                    <li v-for="a in destResults" :key="a.id"
                                        class="cursor-pointer px-3 py-2 text-xs hover:bg-[var(--binary-primary)]/10"
                                        @mousedown.prevent="selectDest(a)"
                                    >
                                        <span class="font-mono font-medium">{{ a.iata_code ?? a.ident }}</span>
                                        <span class="ml-1.5 text-[var(--binary-text-muted)]">{{ a.name }}</span>
                                    </li>
                                </ul>
                            </div>
                            <div>
                                <label class="binary-label mb-1 block text-[10px] uppercase">{{ t('tour_playground.mgmt.label_depart_time') }}</label>
                                <input v-model="flightForm.departure_time" type="datetime-local" class="binary-input w-full" />
                            </div>
                            <div>
                                <label class="binary-label mb-1 block text-[10px] uppercase">{{ t('tour_playground.mgmt.label_arrive_time') }}</label>
                                <input v-model="flightForm.arrival_time" type="datetime-local" class="binary-input w-full" />
                            </div>
                        </div>
                        <p v-if="flightFormError" class="mt-2 text-xs text-red-400">{{ flightFormError }}</p>
                        <button class="binary-button mt-3" :disabled="flightSubmitting" @click="addFlight">
                            {{ flightSubmitting ? t('tour_playground.mgmt.adding') : t('tour_playground.mgmt.add_flight_btn') }}
                        </button>
                    </template>
                    <p v-else class="text-xs text-[var(--binary-text-muted)]">{{ t('tour_playground.mgmt.flights_max_reached') }}</p>
                </div>

                <!-- 飯店 -->
                <div v-show="tourFormMode === 'edit' && tourEditTab === 'hotels'">
                    <p v-if="hotelsLoading" class="mb-3 text-xs text-[var(--binary-text-muted)]">{{ t('common.loading') }}</p>
                    <div v-else-if="editingTourHotels.length" class="mb-4 space-y-1.5">
                        <div
                            v-for="h in editingTourHotels"
                            :key="h.id"
                            class="flex items-center justify-between rounded-lg border border-[var(--binary-outline)]/20 px-3 py-2 text-xs"
                        >
                            <div>
                                <span class="font-medium">{{ h.hotel_name }}</span>
                                <span class="ml-2 text-[var(--binary-text-muted)]">{{ h.room_type }} × {{ h.number_of_rooms }} 間</span>
                                <p class="mt-0.5 text-[var(--binary-text-muted)]">
                                    {{ h.check_in_date }} – {{ h.check_out_date }}（{{ h.nights }} 晚）
                                    ｜ NT$ {{ Number(h.total_cost_price).toLocaleString() }}
                                </p>
                            </div>
                            <button class="binary-ghost-button px-2 py-0.5 text-[10px] text-red-400 hover:text-red-300" @click="deleteHotel(h.id)">{{ t('tour_playground.mgmt.delete') }}</button>
                        </div>
                    </div>
                    <p v-else-if="!hotelsLoading" class="mb-4 text-xs text-[var(--binary-text-muted)]">{{ t('tour_playground.mgmt.no_hotels') }}</p>

                    <template v-if="canAddHotel">
                        <p class="mb-3 text-[10px] uppercase tracking-wide text-[var(--binary-text-muted)]">{{ t('tour_playground.mgmt.add_hotel_title') }}</p>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <div class="sm:col-span-2">
                                <label class="binary-label mb-1 block text-[10px] uppercase">{{ t('tour_playground.mgmt.label_hotel_name') }}</label>
                                <input v-model="hotelForm.hotel_name" type="text" :placeholder="t('tour_playground.mgmt.placeholder_hotel_name')" class="binary-input w-full" />
                            </div>
                            <div>
                                <label class="binary-label mb-1 block text-[10px] uppercase">{{ t('tour_playground.mgmt.label_room_type') }}</label>
                                <select v-model="hotelForm.room_type" class="binary-input w-full">
                                    <option v-for="v in roomTypes" :key="v" :value="v">{{ t(`tour_playground.mgmt.room_${v}`) }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="binary-label mb-1 block text-[10px] uppercase">{{ t('tour_playground.mgmt.label_rooms') }}</label>
                                <input v-model.number="hotelForm.number_of_rooms" type="number" min="1" class="binary-input w-full" />
                            </div>
                            <div>
                                <label class="binary-label mb-1 block text-[10px] uppercase">{{ t('tour_playground.mgmt.label_checkin') }}</label>
                                <input v-model="hotelForm.check_in_date" type="date" class="binary-input w-full" />
                            </div>
                            <div>
                                <label class="binary-label mb-1 block text-[10px] uppercase">{{ t('tour_playground.mgmt.label_checkout') }}</label>
                                <input v-model="hotelForm.check_out_date" type="date" class="binary-input w-full" />
                            </div>
                            <div>
                                <label class="binary-label mb-1 block text-[10px] uppercase">{{ t('tour_playground.mgmt.label_cost_per_night') }}</label>
                                <input v-model.number="hotelForm.cost_price_per_night" type="number" min="0" class="binary-input w-full" />
                            </div>
                        </div>
                        <p v-if="hotelFormError" class="mt-2 text-xs text-red-400">{{ hotelFormError }}</p>
                        <button class="binary-button mt-3" :disabled="hotelSubmitting" @click="addHotel">
                            {{ hotelSubmitting ? t('tour_playground.mgmt.adding') : t('tour_playground.mgmt.add_hotel_btn') }}
                        </button>
                    </template>
                    <p v-else class="text-xs text-[var(--binary-text-muted)]">
                        {{ t('tour_playground.mgmt.hotels_full', { n: editingTour?.duration }) }}
                    </p>
                </div>

            </div>

        </div>

    </div>
</template>

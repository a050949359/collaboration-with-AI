<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { api } from '@/lib/routes';

// ── Types ─────────────────────────────────────────────────

type Passenger = {
    id: number; name: string; email: string; phone: string;
    bookings?: Booking[]; companion_of?: Booking[];
};

type Tour = {
    id: number; code: string; name: string; type: string;
    duration: number; departure_date: string; return_date: string;
    selling_price: number; min_pax: number; max_pax: number;
    booked_pax?: number; is_formed?: boolean; remarks: string | null;
};

type Booking = {
    id: number; booking_reference: string; tour_id: number; passenger_id: number;
    status: string; number_of_travelers: number; discount_amount: number;
    final_amount: number; tour?: Tour; passenger?: Passenger; payments?: Payment[];
};

type Payment = { id: number; amount: number; };

type ExportTask = {
    id: number; type: string; params: string; status: string;
    file_path: string | null; created_at: string;
};

type Airport = {
    id: number; ident: string; name: string;
    iata_code: string | null; municipality: string | null;
};

type TourFlight = {
    id: number; flight_number: string; cabin_class: string;
    origin_airport_id: number; destination_airport_id: number;
    departure_time: string; arrival_time: string;
    cost_price: number; remarks: string | null;
};

type TourHotel = {
    id: number; hotel_name: string; room_type: string;
    check_in_date: string; check_out_date: string;
    number_of_rooms: number; nights: number;
    cost_price_per_night: number; total_cost_price: number;
    remarks: string | null;
};

// ── API helper ────────────────────────────────────────────

async function tourFetch<T>(path: string, init?: RequestInit): Promise<T> {
    const res = await fetch(`/api/v1/tour${path}`, {
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        ...init,
    });
    if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error((err as any).message ?? `Error ${res.status}`);
    }
    if (res.status === 204) return null as T;
    return res.json();
}

function safeParseParams(params: string): Record<string, string> {
    try { return JSON.parse(params); } catch { return {}; }
}

// ── Global ────────────────────────────────────────────────

const activeTab = ref<'passengers' | 'booking' | 'management'>('passengers');

async function switchTab(tab: typeof activeTab.value) {
    activeTab.value = tab;
    if (tab === 'booking' && !tours.value.length) loadTours();
    if (tab === 'management') {
        if (!exports_.value.length) loadExports();
        if (!mgmtTours.value.length) loadMgmtTours();
    }
}

// ── Tab 1: Passengers ─────────────────────────────────────

const PASSENGER_FILTERS = [
    { value: '',               label: '全部' },
    { value: 'booker',         label: '訂購人' },
    { value: 'companion_only', label: '同行人' },
    { value: 'no_booking',     label: '無關聯' },
] as const;

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
        passengerError.value = e instanceof Error ? e.message : '載入失敗';
    } finally {
        passengerLoading.value = false;
    }
}

// ── Tab 2: Booking ────────────────────────────────────────

const tours = ref<Tour[]>([]);
const toursLoading = ref(false);
const hasVacancy = ref(false);
const selectedTour = ref<Tour | null>(null);
const tourListRef = ref<HTMLElement | null>(null);

const bookingForm = ref({
    passenger_id: '',
    companions: [] as string[],
    number_of_travelers: 1,
    discount_amount: 0,
    remarks: '',
});

const bookerName = ref('');
const bookerError = ref('');
const companionNames = ref<string[]>([]);
const companionErrors = ref<string[]>([]);

watch(() => bookingForm.value.number_of_travelers, (n) => {
    const count = Math.max(0, n - 1);
    const cur = bookingForm.value.companions;
    const curNames = companionNames.value;
    const curErrors = companionErrors.value;
    bookingForm.value.companions = Array.from({ length: count }, (_, i) => cur[i] ?? '');
    companionNames.value = Array.from({ length: count }, (_, i) => curNames[i] ?? '');
    companionErrors.value = Array.from({ length: count }, (_, i) => curErrors[i] ?? '');
});

async function lookupPassenger(email: string): Promise<{ id: number; email: string } | null> {
    if (!email) return null;
    try {
        return await tourFetch<{ id: number; email: string }>(`/passengers/lookup?email=${encodeURIComponent(email)}`);
    } catch {
        return null;
    }
}

async function lookupBooker() {
    if (!bookerName.value) { bookingForm.value.passenger_id = ''; bookerError.value = ''; return; }
    const p = await lookupPassenger(bookerName.value);
    if (p) { bookingForm.value.passenger_id = String(p.id); bookerError.value = ''; }
    else { bookingForm.value.passenger_id = ''; bookerError.value = '此 email 尚未登記為旅客'; }
}

async function lookupCompanion(i: number) {
    const email = companionNames.value[i];
    if (!email) { bookingForm.value.companions[i] = ''; companionErrors.value[i] = ''; return; }
    const p = await lookupPassenger(email);
    if (p) { bookingForm.value.companions[i] = String(p.id); companionErrors.value[i] = ''; }
    else { bookingForm.value.companions[i] = ''; companionErrors.value[i] = '此 email 尚未登記為旅客'; }
}

async function randomizeBooker() {
    try {
        const p = await tourFetch<{ id: number; email: string }>('/passengers/random');
        bookingForm.value.passenger_id = String(p.id);
        bookerName.value = p.email;
        bookerError.value = '';
    } catch { /* ignore */ }
}

async function randomizeCompanion(i: number) {
    try {
        const p = await tourFetch<{ id: number; email: string }>('/passengers/random');
        bookingForm.value.companions[i] = String(p.id);
        companionNames.value[i] = p.email;
        companionErrors.value[i] = '';
    } catch { /* ignore */ }
}

const newPassengerTarget = ref<'booker' | number | null>(null);
const newPassengerForm = ref({ name: '', phone: '' });
const newPassengerLoading = ref(false);
const newPassengerError = ref('');

async function createPassenger(target: 'booker' | number) {
    const email = target === 'booker' ? bookerName.value : companionNames.value[target as number];
    newPassengerLoading.value = true;
    newPassengerError.value = '';
    try {
        const p = await tourFetch<{ id: number; email: string }>('/passengers', {
            method: 'POST',
            body: JSON.stringify({ email, name: newPassengerForm.value.name, phone: newPassengerForm.value.phone }),
        });
        if (target === 'booker') {
            bookingForm.value.passenger_id = String(p.id);
            bookerError.value = '';
        } else {
            bookingForm.value.companions[target as number] = String(p.id);
            companionErrors.value[target as number] = '';
        }
        newPassengerForm.value = { name: '', phone: '' };
        newPassengerTarget.value = null;
        loadStats();
    } catch (e) {
        newPassengerError.value = e instanceof Error ? e.message : '新增失敗';
    } finally {
        newPassengerLoading.value = false;
    }
}

const bookingLoading = ref(false);
const bookingError = ref('');
const bookingSuccess = ref('');

const finalAmount = computed(() =>
    Math.max(0, (selectedTour.value?.selling_price ?? 0) - bookingForm.value.discount_amount)
);

const passengerConflicts = computed(() => {
    const bookerId = bookingForm.value.passenger_id;
    return bookingForm.value.companions.map((id, i) => {
        if (id && id === bookerId) return `同行人 ${i + 1} 與付款人相同`;
        const dupIdx = bookingForm.value.companions.findIndex((c, j) => j < i && c === id && id !== '');
        if (dupIdx !== -1) return `同行人 ${i + 1} 與同行人 ${dupIdx + 1} 重複`;
        return null;
    }).filter(Boolean);
});

async function loadTours() {
    toursLoading.value = true;
    try {
        tours.value = await tourFetch<Tour[]>(hasVacancy.value ? '/tours?has_vacancy=1' : '/tours');
    } finally {
        toursLoading.value = false;
    }
}

function selectTour(tour: Tour) {
    if (selectedTour.value?.id === tour.id) {
        selectedTour.value = null;
        return;
    }
    selectedTour.value = tour;
    bookingError.value = '';
    bookingSuccess.value = '';
    // 捲到選中的 item（等 transition 結束後）
    setTimeout(() => {
        tourListRef.value?.querySelector<HTMLElement>(`[data-tour-id="${tour.id}"]`)?.scrollIntoView({ block: 'nearest' });
    }, 310);
}

async function submitBooking() {
    if (!selectedTour.value || bookingLoading.value) return;
    bookingLoading.value = true;
    bookingError.value = '';
    bookingSuccess.value = '';
    try {
        const companions = bookingForm.value.companions
            .map(s => parseInt(s)).filter(n => !isNaN(n) && n > 0);
        await tourFetch('/bookings', {
            method: 'POST',
            body: JSON.stringify({
                tour_id: selectedTour.value.id,
                passenger_id: parseInt(bookingForm.value.passenger_id),
                number_of_travelers: bookingForm.value.number_of_travelers,
                discount_amount: bookingForm.value.discount_amount,
                final_amount: finalAmount.value,
                ...(companions.length && { companions }),
                ...(bookingForm.value.remarks && { remarks: bookingForm.value.remarks }),
            }),
        });
        bookingSuccess.value = '訂單建立成功！';
        bookingForm.value = { passenger_id: '', companions: [], number_of_travelers: 1, discount_amount: 0, remarks: '' };
        bookerName.value = '';
        bookerError.value = '';
        companionNames.value = [];
        companionErrors.value = [];
        newPassengerTarget.value = null;
        newPassengerForm.value = { name: '', phone: '' };
        newPassengerError.value = '';
        selectedTour.value = null;
        await loadTours();
    } catch (e) {
        bookingError.value = e instanceof Error ? e.message : '建立失敗';
    } finally {
        bookingLoading.value = false;
    }
}

// ── Tab 3: Management ─────────────────────────────────────

const mgmtTab = ref<'bookings' | 'exports' | 'tours'>('bookings');

// --- Bookings accordion ---
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

// --- Exports ---
const exports_ = ref<ExportTask[]>([]);
const exportsLoading = ref(false);
const exportingId = ref<number | null>(null);
const exportError = ref('');
const pollingMap = new Map<number, ReturnType<typeof setInterval>>();

function stopAllPolling() {
    pollingMap.forEach((_, id) => stopPolling(id));
}

watch(activeTab, (tab) => {
    if (tab !== 'management') stopAllPolling();
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

function stopPolling(id: number) {
    clearInterval(pollingMap.get(id)!);
    pollingMap.delete(id);
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
        exportError.value = e instanceof Error ? e.message : '匯出失敗';
    } finally {
        exportingId.value = null;
    }
}

function openExportDownload(id: number) {
    window.open(api.tour.exportDownload(id), '_blank');
}

// --- Tours ---
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
    flightForm.value = { flight_number: '', cabin_class: 'economy', origin_airport_id: 0, destination_airport_id: 0, departure_time: '', arrival_time: '', cost_price: 0, remarks: '' };
    hotelForm.value = { hotel_name: '', check_in_date: '', check_out_date: '', room_type: 'twin', number_of_rooms: 1, cost_price_per_night: 0, remarks: '' };
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
            tourFormSuccess.value = '行程建立成功！';
        } else if (editingTour.value) {
            await tourFetch(`/tours/${editingTour.value.id}`, { method: 'PUT', body: JSON.stringify(payload) });
            tourFormSuccess.value = '行程更新成功！';
        }
        cancelTourForm();
        await loadMgmtTours();
    } catch (e) {
        tourFormError.value = e instanceof Error ? e.message : '操作失敗';
    } finally {
        tourFormLoading.value = false;
    }
}

// --- Flights ---
const editingTourFlights = ref<TourFlight[]>([]);
const flightsLoading = ref(false);
const flightForm = ref({ flight_number: '', cabin_class: 'economy', origin_airport_id: 0, destination_airport_id: 0, departure_time: '', arrival_time: '', cost_price: 0, remarks: '' });
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
        flightForm.value = { flight_number: '', cabin_class: 'economy', origin_airport_id: 0, destination_airport_id: 0, departure_time: '', arrival_time: '', cost_price: 0, remarks: '' };
        originQuery.value = ''; destQuery.value = '';
        await loadEditingTourFlights();
    } catch (e) {
        flightFormError.value = e instanceof Error ? e.message : '新增失敗';
    } finally {
        flightSubmitting.value = false;
    }
}

async function deleteFlight(flightId: number) {
    if (!editingTour.value) return;
    await tourFetch(`/${editingTour.value.id}/flights/${flightId}`, { method: 'DELETE' }).catch(() => null);
    editingTourFlights.value = editingTourFlights.value.filter(f => f.id !== flightId);
}

// --- Hotels ---
const editingTourHotels = ref<TourHotel[]>([]);
const hotelsLoading = ref(false);
const hotelForm = ref({ hotel_name: '', check_in_date: '', check_out_date: '', room_type: 'twin', number_of_rooms: 1, cost_price_per_night: 0, remarks: '' });
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
        hotelForm.value = { hotel_name: '', check_in_date: '', check_out_date: '', room_type: 'twin', number_of_rooms: 1, cost_price_per_night: 0, remarks: '' };
        await loadEditingTourHotels();
    } catch (e) {
        hotelFormError.value = e instanceof Error ? e.message : '新增失敗';
    } finally {
        hotelSubmitting.value = false;
    }
}

async function deleteHotel(hotelId: number) {
    if (!editingTour.value) return;
    await tourFetch(`/${editingTour.value.id}/hotels/${hotelId}`, { method: 'DELETE' }).catch(() => null);
    editingTourHotels.value = editingTourHotels.value.filter(h => h.id !== hotelId);
}

// ── Stats ─────────────────────────────────────────────────

type Stats = { passengers_count: number; bookings_count: number; tours_count: number; };

const stats = ref<Stats | null>(null);
const statsLoading = ref(false);

async function loadStats() {
    statsLoading.value = true;
    try { stats.value = await tourFetch<Stats>('/stats'); }
    finally { statsLoading.value = false; }
}

// ── Init ──────────────────────────────────────────────────

onMounted(loadStats);
</script>

<template>
    <Head title="Tour Playground" />
    <AppLayout>
        <main class="pt-24 pb-24">
            <section class="mx-auto max-w-screen-xl px-6 py-12 md:px-8">

                <!-- Header -->
                <div class="binary-card-raised mb-6 rounded-2xl">
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <h1 class="binary-display text-3xl font-black uppercase tracking-tight">Tour Playground</h1>
                            <p class="mt-1 text-sm text-[var(--binary-text-muted)]">Queue / Worker 練習環境｜資料與主系統隔離</p>
                        </div>

                        <!-- Stat Cards -->
                        <div class="flex gap-3">
                            <template v-if="statsLoading">
                                <div v-for="i in 3" :key="i"
                                    class="h-16 w-28 animate-pulse rounded-xl bg-[var(--binary-surface-container)]" />
                            </template>
                            <template v-else-if="stats">
                                <div
                                    v-for="card in [
                                        { label: '旅客數', value: stats.passengers_count },
                                        { label: '訂單數', value: stats.bookings_count },
                                        { label: '行程數', value: stats.tours_count },
                                    ]"
                                    :key="card.label"
                                    class="min-w-[6.5rem] rounded-xl border border-[var(--binary-outline)]/20 bg-[var(--binary-surface-container)] px-4 py-3 text-center"
                                >
                                    <p class="binary-display text-2xl font-black text-[var(--binary-primary)]">
                                        {{ card.value.toLocaleString() }}
                                    </p>
                                    <p class="mt-0.5 text-[10px] uppercase tracking-wide text-[var(--binary-text-muted)]">{{ card.label }}</p>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Tab Navigation -->
                <div class="mb-6 flex gap-1 border-b border-[var(--binary-outline)]/20 pb-0">
                    <button
                        v-for="tab in [
                            { key: 'passengers', label: '旅客介面' },
                            { key: 'booking',    label: '購票介面' },
                            { key: 'management', label: '管理介面' },
                        ]"
                        :key="tab.key"
                        class="px-5 py-2.5 text-sm font-medium uppercase tracking-wide transition-colors border-b-2 -mb-[2px]"
                        :class="activeTab === tab.key
                            ? 'border-[var(--binary-primary)] text-[var(--binary-primary)]'
                            : 'border-transparent text-[var(--binary-text-muted)] hover:text-[var(--binary-text)]'"
                        @click="switchTab(tab.key as any)"
                    >{{ tab.label }}</button>
                </div>

                <!-- ═══════════════════════════════════════════
                     Tab 1: Passengers
                ════════════════════════════════════════════ -->
                <div v-show="activeTab === 'passengers'">
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
                            >{{ f.label }}</button>
                            <button class="binary-button ml-auto px-4 py-1.5 text-xs" :disabled="passengerLoading" @click="loadRandomPassenger">
                                {{ passengerLoading ? '載入中⋯' : '隨機挑選' }}
                            </button>
                        </div>

                        <!-- Empty state -->
                        <p v-if="!passenger && !passengerLoading && !passengerError" class="py-10 text-center text-sm text-[var(--binary-text-muted)]">
                            選擇類型後點「隨機挑選」
                        </p>

                        <p v-if="passengerLoading" class="py-10 text-center text-sm text-[var(--binary-text-muted)]">載入中⋯</p>
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
                                    <p class="mb-2 text-[10px] uppercase text-[var(--binary-text-muted)]">訂單（{{ passenger.bookings.length }} 筆）</p>
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
                                                {{ b.tour?.code }} ｜ {{ b.number_of_travelers }} 人 ｜ NT$ {{ Number(b.final_amount).toLocaleString() }}
                                            </p>
                                            <p class="mt-0.5 text-[var(--binary-text-muted)]">
                                                已付：NT$ {{ b.payments?.reduce((s, p) => s + Number(p.amount), 0).toLocaleString() ?? 0 }}
                                                <span v-if="b.payments && b.payments.reduce((s, p) => s + Number(p.amount), 0) >= Number(b.final_amount)"
                                                    class="ml-1 text-[var(--binary-primary)]">✓ 已付清</span>
                                            </p>
                                        </div>
                                    </div>
                                </template>

                                <!-- Companion of (companion_only) -->
                                <template v-else-if="passengerFilter === 'companion_only' && passenger.companion_of?.length">
                                    <p class="mb-2 text-[10px] uppercase text-[var(--binary-text-muted)]">同行過的團（{{ passenger.companion_of.length }} 筆）</p>
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
                                    <p class="text-xs text-[var(--binary-text-muted)]">此旅客無任何訂單或同行紀錄</p>
                                </template>

                                <!-- 全部：同時顯示訂單與同行紀錄 -->
                                <template v-else-if="passengerFilter === ''">
                                    <template v-if="passenger.bookings?.length">
                                        <p class="mb-2 text-[10px] uppercase text-[var(--binary-text-muted)]">訂單（{{ passenger.bookings.length }} 筆）</p>
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
                                                    {{ b.tour?.code }} ｜ {{ b.number_of_travelers }} 人 ｜ NT$ {{ Number(b.final_amount).toLocaleString() }}
                                                </p>
                                                <p class="mt-0.5 text-[var(--binary-text-muted)]">
                                                    已付：NT$ {{ b.payments?.reduce((s, p) => s + Number(p.amount), 0).toLocaleString() ?? 0 }}
                                                    <span v-if="b.payments && b.payments.reduce((s, p) => s + Number(p.amount), 0) >= Number(b.final_amount)"
                                                        class="ml-1 text-[var(--binary-primary)]">✓ 已付清</span>
                                                </p>
                                            </div>
                                        </div>
                                    </template>

                                    <template v-if="passenger.companion_of?.length">
                                        <p class="mb-2 text-[10px] uppercase text-[var(--binary-text-muted)]">同行過的團（{{ passenger.companion_of.length }} 筆）</p>
                                        <div class="flex flex-wrap gap-1.5">
                                            <span
                                                v-for="b in passenger.companion_of"
                                                :key="b.id"
                                                class="rounded bg-[var(--binary-outline)]/10 px-2 py-1 text-xs"
                                            >{{ b.tour?.code ?? `#${b.tour_id}` }}</span>
                                        </div>
                                    </template>

                                    <p v-if="!passenger.bookings?.length && !passenger.companion_of?.length"
                                        class="text-xs text-[var(--binary-text-muted)]">此旅客無任何訂單或同行紀錄</p>
                                </template>
                            </div>
                        </template>

                    </div>
                </div>

                <!-- ═══════════════════════════════════════════
                     Tab 2: Booking
                ════════════════════════════════════════════ -->
                <div v-show="activeTab === 'booking'">
                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

                        <!-- Tour List -->
                        <div class="binary-card-raised rounded-2xl">
                            <div class="mb-4 flex items-center justify-between">
                                <h2 class="text-sm font-bold uppercase tracking-wide">選擇行程</h2>
                                <label class="flex cursor-pointer items-center gap-1.5 text-xs text-[var(--binary-text-muted)]">
                                    <input type="checkbox" v-model="hasVacancy" @change="loadTours" class="accent-[var(--binary-primary)]" />
                                    只看有空位
                                </label>
                            </div>
                            <p v-if="toursLoading" class="py-6 text-center text-sm text-[var(--binary-text-muted)]">載入中⋯</p>
                            <div
                                ref="tourListRef"
                                class="space-y-2 overflow-y-auto pr-1 transition-all duration-300"
                                :class="selectedTour ? 'max-h-20 lg:max-h-[480px]' : 'max-h-[480px]'"
                            >
                                <button
                                    v-for="tour in tours"
                                    :key="tour.id"
                                    :data-tour-id="tour.id"
                                    class="w-full rounded-lg border p-3 text-left transition-colors"
                                    :class="selectedTour?.id === tour.id
                                        ? 'border-[var(--binary-primary)] bg-[var(--binary-primary)]/10'
                                        : 'border-[var(--binary-outline)]/20 hover:border-[var(--binary-outline)]/50'"
                                    @click="selectTour(tour)"
                                >
                                    <div class="flex items-center justify-between">
                                        <span class="font-mono text-xs text-[var(--binary-text-muted)]">{{ tour.code }}</span>
                                        <div class="flex items-center gap-2">
                                            <span class="rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                                                :class="tour.is_formed
                                                    ? 'bg-green-500/15 text-green-400'
                                                    : 'bg-yellow-500/15 text-yellow-400'"
                                            >{{ tour.is_formed ? '已成團' : '未成團' }}</span>
                                            <span v-if="(tour.booked_pax ?? 0) > tour.max_pax"
                                                class="rounded-full bg-red-500/15 px-1.5 py-0.5 text-[10px] font-medium text-red-400">
                                                超收 {{ (tour.booked_pax ?? 0) - tour.max_pax }} 人
                                            </span>
                                            <span class="text-xs" :class="(tour.booked_pax ?? 0) >= tour.max_pax ? 'text-red-400' : 'text-[var(--binary-text-muted)]'">
                                                {{ tour.booked_pax ?? 0 }} / {{ tour.max_pax }} 人
                                            </span>
                                        </div>
                                    </div>
                                    <p class="mt-0.5 text-sm font-medium">{{ tour.name }}</p>
                                    <p class="mt-0.5 text-xs text-[var(--binary-text-muted)]">
                                        {{ tour.departure_date }} ｜ {{ tour.duration }} 天 ｜
                                        <span class="text-[var(--binary-primary)]">NT$ {{ Number(tour.selling_price).toLocaleString() }}</span>
                                    </p>
                                </button>
                            </div>
                        </div>

                        <!-- Booking Form -->
                        <div class="binary-card-raised rounded-2xl">
                            <h2 class="mb-4 text-sm font-bold uppercase tracking-wide">訂單資訊</h2>

                            <p v-if="!selectedTour" class="py-10 text-center text-sm text-[var(--binary-text-muted)]">
                                請先選擇左側行程
                            </p>

                            <template v-else>
                                <div class="mb-5 rounded-lg bg-[var(--binary-primary)]/10 px-3 py-2.5">
                                    <p class="text-sm font-medium">{{ selectedTour.name }}</p>
                                    <p class="mt-0.5 font-mono text-xs text-[var(--binary-text-muted)]">{{ selectedTour.code }}</p>
                                </div>

                                <div class="space-y-4">
                                    <!-- 付款人：獨佔一列 -->
                                    <div>
                                        <label class="binary-label mb-1 block text-[10px] uppercase">付款人</label>
                                        <div class="flex gap-2">
                                            <input
                                                v-model="bookerName"
                                                type="email"
                                                placeholder="輸入 email"
                                                class="binary-input flex-1"
                                                @blur="lookupBooker"
                                            />
                                            <button class="binary-ghost-button px-3 text-xs" @click="randomizeBooker">隨機</button>
                                        </div>
                                        <div v-if="bookerError" class="mt-2 space-y-2">
                                            <p class="text-xs text-yellow-400">
                                                ⚠ {{ bookerError }}
                                                <button
                                                    class="ml-2 text-xs underline hover:text-[var(--binary-primary)]"
                                                    @click="newPassengerTarget = 'booker'"
                                                >新增旅客</button>
                                            </p>
                                            <div v-if="newPassengerTarget === 'booker'" class="rounded-lg border border-[var(--binary-outline)]/20 p-3 space-y-2">
                                                <div class="grid grid-cols-2 gap-2">
                                                    <div>
                                                        <label class="binary-label mb-1 block text-[10px] uppercase">姓名</label>
                                                        <input v-model="newPassengerForm.name" type="text" placeholder="陳大文" class="binary-input w-full" />
                                                    </div>
                                                    <div>
                                                        <label class="binary-label mb-1 block text-[10px] uppercase">電話</label>
                                                        <input v-model="newPassengerForm.phone" type="tel" placeholder="0912345678" class="binary-input w-full" />
                                                    </div>
                                                </div>
                                                <p v-if="newPassengerError" class="text-xs text-red-400">{{ newPassengerError }}</p>
                                                <div class="flex gap-2">
                                                    <button class="binary-button px-3 py-1 text-xs" :disabled="newPassengerLoading" @click="createPassenger('booker')">
                                                        {{ newPassengerLoading ? '新增中⋯' : '確認新增' }}
                                                    </button>
                                                    <button class="binary-ghost-button px-3 py-1 text-xs" @click="newPassengerTarget = null">取消</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- 旅行人數 1 : 同行人 5 -->
                                    <div class="grid grid-cols-6 gap-3">
                                        <div class="col-span-1">
                                            <label class="binary-label mb-1 block text-[10px] uppercase">旅行人數</label>
                                            <input v-model.number="bookingForm.number_of_travelers" type="number" min="1" class="binary-input w-full" />
                                        </div>
                                        <div
                                            v-for="(_, i) in bookingForm.companions"
                                            :key="i"
                                            :class="i === 0 ? 'col-span-5' : 'col-start-2 col-span-5'"
                                        >
                                            <label class="binary-label mb-1 block text-[10px] uppercase">同行人 {{ i + 1 }}</label>
                                            <div class="flex gap-2">
                                                <input
                                                    v-model="companionNames[i]"
                                                    type="email"
                                                    placeholder="輸入 email"
                                                    class="binary-input flex-1"
                                                    @blur="lookupCompanion(i)"
                                                />
                                                <button class="binary-ghost-button px-3 text-xs" @click="randomizeCompanion(i)">隨機</button>
                                            </div>
                                            <div v-if="companionErrors[i]" class="mt-2 space-y-2">
                                                <p class="text-xs text-yellow-400">
                                                    ⚠ {{ companionErrors[i] }}
                                                    <button
                                                        class="ml-2 text-xs underline hover:text-[var(--binary-primary)]"
                                                        @click="newPassengerTarget = i"
                                                    >新增旅客</button>
                                                </p>
                                                <div v-if="newPassengerTarget === i" class="rounded-lg border border-[var(--binary-outline)]/20 p-3 space-y-2">
                                                    <div class="grid grid-cols-2 gap-2">
                                                        <div>
                                                            <label class="binary-label mb-1 block text-[10px] uppercase">姓名</label>
                                                            <input v-model="newPassengerForm.name" type="text" placeholder="陳大文" class="binary-input w-full" />
                                                        </div>
                                                        <div>
                                                            <label class="binary-label mb-1 block text-[10px] uppercase">電話</label>
                                                            <input v-model="newPassengerForm.phone" type="tel" placeholder="0912345678" class="binary-input w-full" />
                                                        </div>
                                                    </div>
                                                    <p v-if="newPassengerError" class="text-xs text-red-400">{{ newPassengerError }}</p>
                                                    <div class="flex gap-2">
                                                        <button class="binary-button px-3 py-1 text-xs" :disabled="newPassengerLoading" @click="createPassenger(i)">
                                                            {{ newPassengerLoading ? '新增中⋯' : '確認新增' }}
                                                        </button>
                                                        <button class="binary-ghost-button px-3 py-1 text-xs" @click="newPassengerTarget = null">取消</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="binary-label mb-1 block text-[10px] uppercase">折扣金額</label>
                                        <input v-model.number="bookingForm.discount_amount" type="number" min="0" class="binary-input w-full" />
                                    </div>

                                    <div>
                                        <label class="binary-label mb-1 block text-[10px] uppercase">備註（可選）</label>
                                        <input v-model="bookingForm.remarks" type="text" class="binary-input w-full" />
                                    </div>

                                    <!-- Final Amount -->
                                    <div class="rounded-lg border border-[var(--binary-outline)]/20 px-4 py-3">
                                        <p class="text-[10px] uppercase text-[var(--binary-text-muted)]">應付金額</p>
                                        <p class="text-2xl font-bold text-[var(--binary-primary)]">NT$ {{ finalAmount.toLocaleString() }}</p>
                                    </div>

                                    <!-- Fake payment placeholder -->
                                    <div class="rounded-lg border border-dashed border-[var(--binary-outline)]/30 px-4 py-3 text-center text-xs text-[var(--binary-text-muted)]">
                                        💳 金流介接（佔位）
                                    </div>

                                    <div v-if="passengerConflicts.length" class="space-y-1">
                                        <p v-for="msg in passengerConflicts" :key="msg" class="text-xs text-yellow-400">⚠ {{ msg }}</p>
                                    </div>

                                    <p v-if="bookingError" class="text-xs text-red-400">{{ bookingError }}</p>
                                    <p v-if="bookingSuccess" class="text-xs text-[var(--binary-primary)]">{{ bookingSuccess }}</p>

                                    <button
                                        class="binary-button w-full"
                                        :class="{ 'cursor-not-allowed opacity-50': bookingLoading || passengerConflicts.length > 0 }"
                                        :disabled="bookingLoading || passengerConflicts.length > 0"
                                        @click="submitBooking"
                                    >{{ bookingLoading ? '建立中⋯' : '確認訂單' }}</button>
                                </div>
                            </template>
                        </div>

                    </div>
                </div>

                <!-- ═══════════════════════════════════════════
                     Tab 3: Management
                ════════════════════════════════════════════ -->
                <div v-show="activeTab === 'management'">

                    <!-- Sub-tabs -->
                    <div class="mb-4 flex gap-1">
                        <button
                            v-for="t in [
                                { key: 'bookings', label: '訂單列表' },
                                { key: 'exports',  label: '匯出' },
                                { key: 'tours',    label: '行程管理' },
                            ]"
                            :key="t.key"
                            class="rounded-md px-3 py-1.5 text-xs font-medium uppercase transition-colors"
                            :class="mgmtTab === t.key
                                ? 'bg-[var(--binary-primary)]/20 text-[var(--binary-primary)]'
                                : 'text-[var(--binary-text-muted)] hover:text-[var(--binary-text)]'"
                            @click="mgmtTab = t.key as any"
                        >{{ t.label }}</button>
                    </div>

                    <!-- ─── Bookings ─────────────────────────── -->
                    <div v-show="mgmtTab === 'bookings'" class="binary-card-raised rounded-2xl">
                        <div class="mb-4 flex items-center justify-between">
                            <h2 class="text-sm font-bold uppercase tracking-wide">行程訂單</h2>
                            <button class="binary-ghost-button px-3 py-1 text-xs" @click="loadMgmtTours">重新整理</button>
                        </div>
                        <p v-if="exportError" class="mb-3 text-xs text-red-400">{{ exportError }}</p>
                        <p v-if="mgmtToursLoading" class="py-6 text-center text-sm text-[var(--binary-text-muted)]">載入中⋯</p>
                        <div v-else class="space-y-1.5">
                            <div
                                v-for="tour in mgmtTours"
                                :key="tour.id"
                                class="overflow-hidden rounded-lg border border-[var(--binary-outline)]/20"
                            >
                                <!-- Tour row -->
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
                                                {{ tour.is_formed ? '已成團' : '未成團' }}
                                            </span>
                                            <span v-if="(tour.booked_pax ?? 0) > tour.max_pax"
                                                class="rounded-full bg-red-500/15 px-1.5 py-0.5 text-[10px] font-medium text-red-400">
                                                超收 {{ (tour.booked_pax ?? 0) - tour.max_pax }} 人
                                            </span>
                                        </div>
                                        <p class="truncate text-xs font-medium">{{ tour.name }}</p>
                                    </div>
                                    <span class="shrink-0 text-xs text-[var(--binary-text-muted)]">
                                        {{ tour.booked_pax ?? 0 }} / {{ tour.max_pax }} 人
                                    </span>
                                    <button
                                        class="binary-ghost-button shrink-0 px-2 py-0.5 text-[10px]"
                                        :disabled="exportingId === tour.id"
                                        @click.stop="triggerExport(tour)"
                                    >{{ exportingId === tour.id ? '送出中⋯' : '匯出' }}</button>
                                </div>

                                <!-- Expanded bookings -->
                                <div v-if="expandedTourId === tour.id"
                                    class="border-t border-[var(--binary-outline)]/20 bg-[var(--binary-surface-container)]/30 px-3 py-2"
                                >
                                    <p v-if="loadingTourId === tour.id" class="py-3 text-center text-xs text-[var(--binary-text-muted)]">載入中⋯</p>
                                    <template v-else-if="tourBookingsCache[tour.id]">
                                        <p v-if="!tourBookingsCache[tour.id].length" class="py-3 text-center text-xs text-[var(--binary-text-muted)]">尚無訂單</p>
                                        <table v-else class="w-full">
                                            <thead>
                                                <tr class="text-left text-[10px] uppercase text-[var(--binary-text-muted)]">
                                                    <th class="pb-1.5 pr-3">訂單編號</th>
                                                    <th class="pb-1.5 pr-3">旅客</th>
                                                    <th class="pb-1.5 pr-3">人數</th>
                                                    <th class="pb-1.5 pr-3">狀態</th>
                                                    <th class="pb-1.5">應付</th>
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
                                <h2 class="text-sm font-bold uppercase tracking-wide">匯出紀錄</h2>
                                <button class="binary-ghost-button px-3 py-1 text-xs" @click="loadExports">重新整理</button>
                            </div>
                            <p v-if="exportsLoading" class="py-4 text-center text-sm text-[var(--binary-text-muted)]">載入中⋯</p>
                            <div v-else class="space-y-2">
                                <p v-if="!exports_.length" class="py-4 text-center text-sm text-[var(--binary-text-muted)]">尚無匯出記錄</p>
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
                                        >下載</button>
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
                                <h2 class="text-sm font-bold uppercase tracking-wide">行程列表</h2>
                                <div class="flex items-center gap-2">
                                    <button class="binary-ghost-button px-3 py-1 text-xs" @click="loadMgmtTours">重新整理</button>
                                    <button class="binary-button px-3 py-1 text-xs" @click="openCreateForm">+ 新增</button>
                                </div>
                            </div>
                            <p v-if="mgmtToursLoading" class="py-4 text-center text-sm text-[var(--binary-text-muted)]">載入中⋯</p>
                            <div v-else class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b border-[var(--binary-outline)]/20 text-left text-[10px] uppercase text-[var(--binary-text-muted)]">
                                            <th class="pb-2 pr-4">Code</th>
                                            <th class="pb-2 pr-4">名稱</th>
                                            <th class="pb-2 pr-4">出發日</th>
                                            <th class="pb-2 pr-4">售價</th>
                                            <th class="pb-2 pr-4">人數限制</th>
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
                                                >編輯</button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Create / Edit Panel -->
                        <div v-if="showTourForm" class="binary-card-raised rounded-2xl">

                            <!-- Panel header -->
                            <div class="mb-4 flex items-center justify-between">
                                <div>
                                    <h2 class="text-sm font-bold uppercase tracking-wide">
                                        {{ tourFormMode === 'create' ? '新增行程' : editingTour?.name }}
                                    </h2>
                                    <p v-if="tourFormMode === 'edit'" class="mt-0.5 font-mono text-xs text-[var(--binary-text-muted)]">
                                        {{ editingTour?.code }}
                                    </p>
                                </div>
                                <button class="binary-ghost-button px-3 py-1 text-xs" @click="cancelTourForm">取消</button>
                            </div>

                            <!-- Sub-tabs (edit mode only) -->
                            <div v-if="tourFormMode === 'edit'" class="mb-5 flex border-b border-[var(--binary-outline)]/20">
                                <button
                                    v-for="t in [
                                        { key: 'info',    label: '基本資料' },
                                        { key: 'flights', label: '航班' },
                                        { key: 'hotels',  label: '飯店' },
                                    ]"
                                    :key="t.key"
                                    class="px-4 py-2 text-xs font-medium uppercase tracking-wide border-b-2 -mb-[2px] transition-colors"
                                    :class="tourEditTab === t.key
                                        ? 'border-[var(--binary-primary)] text-[var(--binary-primary)]'
                                        : 'border-transparent text-[var(--binary-text-muted)] hover:text-[var(--binary-text)]'"
                                    @click="tourEditTab = (t.key as any)"
                                >{{ t.label }}</button>
                            </div>

                            <!-- ── 基本資料 ── -->
                            <div v-show="tourFormMode === 'create' || tourEditTab === 'info'">
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="binary-label mb-1 block text-[10px] uppercase">名稱</label>
                                        <input v-model="tourForm.name" type="text" placeholder="北海道春季賞花團" class="binary-input w-full" />
                                    </div>
                                    <div>
                                        <label class="binary-label mb-1 block text-[10px] uppercase">類型</label>
                                        <select v-model="tourForm.type" class="binary-input w-full">
                                            <option value="group">Group</option>
                                            <option value="fit">FIT</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="binary-label mb-1 block text-[10px] uppercase">天數</label>
                                        <input v-model.number="tourForm.duration" type="number" min="1" class="binary-input w-full" />
                                    </div>
                                    <div>
                                        <label class="binary-label mb-1 block text-[10px] uppercase">出發日</label>
                                        <input v-model="tourForm.departure_date" type="date" class="binary-input w-full" />
                                    </div>
                                    <div>
                                        <label class="binary-label mb-1 block text-[10px] uppercase">回程日</label>
                                        <input v-model="tourForm.return_date" type="date" class="binary-input w-full" />
                                    </div>
                                    <div>
                                        <label class="binary-label mb-1 block text-[10px] uppercase">售價 (NT$)</label>
                                        <input v-model="tourForm.selling_price" type="number" min="0" class="binary-input w-full" />
                                    </div>
                                    <div class="grid grid-cols-2 gap-2">
                                        <div>
                                            <label class="binary-label mb-1 block text-[10px] uppercase">最少人數</label>
                                            <input v-model.number="tourForm.min_pax" type="number" min="1" class="binary-input w-full" />
                                        </div>
                                        <div>
                                            <label class="binary-label mb-1 block text-[10px] uppercase">最多人數</label>
                                            <input v-model.number="tourForm.max_pax" type="number" min="1" class="binary-input w-full" />
                                        </div>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="binary-label mb-1 block text-[10px] uppercase">備註（可選）</label>
                                        <textarea v-model="tourForm.remarks" rows="2" class="binary-input w-full resize-none" />
                                    </div>
                                </div>
                                <div class="mt-4 flex items-center gap-3">
                                    <button class="binary-button" :disabled="tourFormLoading" @click="submitTour">
                                        {{ tourFormLoading ? '處理中⋯' : (tourFormMode === 'create' ? '新增' : '更新') }}
                                    </button>
                                    <p v-if="tourFormError" class="text-xs text-red-400">{{ tourFormError }}</p>
                                    <p v-if="tourFormSuccess" class="text-xs text-[var(--binary-primary)]">{{ tourFormSuccess }}</p>
                                </div>
                            </div>

                            <!-- ── 航班 ── -->
                            <div v-show="tourFormMode === 'edit' && tourEditTab === 'flights'">
                                <p v-if="flightsLoading" class="mb-3 text-xs text-[var(--binary-text-muted)]">載入中⋯</p>
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
                                        <button class="binary-ghost-button px-2 py-0.5 text-[10px] text-red-400 hover:text-red-300" @click="deleteFlight(f.id)">刪除</button>
                                    </div>
                                </div>
                                <p v-else-if="!flightsLoading" class="mb-4 text-xs text-[var(--binary-text-muted)]">尚未設定航班</p>

                                <template v-if="canAddFlight">
                                    <p class="mb-3 text-[10px] uppercase tracking-wide text-[var(--binary-text-muted)]">新增航班</p>
                                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                        <div>
                                            <label class="binary-label mb-1 block text-[10px] uppercase">航班號</label>
                                            <input v-model="flightForm.flight_number" type="text" placeholder="CI101" class="binary-input w-full" />
                                        </div>
                                        <div>
                                            <label class="binary-label mb-1 block text-[10px] uppercase">艙等</label>
                                            <select v-model="flightForm.cabin_class" class="binary-input w-full">
                                                <option value="economy">經濟艙</option>
                                                <option value="premium_economy">豪華經濟艙</option>
                                                <option value="business">商務艙</option>
                                                <option value="first">頭等艙</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="binary-label mb-1 block text-[10px] uppercase">成本價</label>
                                            <input v-model.number="flightForm.cost_price" type="number" min="0" class="binary-input w-full" />
                                        </div>
                                        <div class="relative">
                                            <label class="binary-label mb-1 block text-[10px] uppercase">出發機場</label>
                                            <input v-model="originQuery" type="text" placeholder="搜尋機場…" class="binary-input w-full"
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
                                            <label class="binary-label mb-1 block text-[10px] uppercase">抵達機場</label>
                                            <input v-model="destQuery" type="text" placeholder="搜尋機場…" class="binary-input w-full"
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
                                            <label class="binary-label mb-1 block text-[10px] uppercase">出發時間</label>
                                            <input v-model="flightForm.departure_time" type="datetime-local" class="binary-input w-full" />
                                        </div>
                                        <div>
                                            <label class="binary-label mb-1 block text-[10px] uppercase">抵達時間</label>
                                            <input v-model="flightForm.arrival_time" type="datetime-local" class="binary-input w-full" />
                                        </div>
                                    </div>
                                    <p v-if="flightFormError" class="mt-2 text-xs text-red-400">{{ flightFormError }}</p>
                                    <button class="binary-button mt-3" :disabled="flightSubmitting" @click="addFlight">
                                        {{ flightSubmitting ? '新增中⋯' : '+ 新增航班' }}
                                    </button>
                                </template>
                                <p v-else class="text-xs text-[var(--binary-text-muted)]">已設定去回程，如需調整請先刪除</p>
                            </div>

                            <!-- ── 飯店 ── -->
                            <div v-show="tourFormMode === 'edit' && tourEditTab === 'hotels'">
                                <p v-if="hotelsLoading" class="mb-3 text-xs text-[var(--binary-text-muted)]">載入中⋯</p>
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
                                        <button class="binary-ghost-button px-2 py-0.5 text-[10px] text-red-400 hover:text-red-300" @click="deleteHotel(h.id)">刪除</button>
                                    </div>
                                </div>
                                <p v-else-if="!hotelsLoading" class="mb-4 text-xs text-[var(--binary-text-muted)]">尚未設定飯店</p>

                                <template v-if="canAddHotel">
                                    <p class="mb-3 text-[10px] uppercase tracking-wide text-[var(--binary-text-muted)]">新增飯店</p>
                                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                        <div class="sm:col-span-2">
                                            <label class="binary-label mb-1 block text-[10px] uppercase">飯店名稱</label>
                                            <input v-model="hotelForm.hotel_name" type="text" placeholder="北海道 Grand Hotel" class="binary-input w-full" />
                                        </div>
                                        <div>
                                            <label class="binary-label mb-1 block text-[10px] uppercase">房型</label>
                                            <select v-model="hotelForm.room_type" class="binary-input w-full">
                                                <option value="single">單人房</option>
                                                <option value="double">雙人房</option>
                                                <option value="twin">雙床房</option>
                                                <option value="suite">套房</option>
                                                <option value="deluxe">豪華房</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="binary-label mb-1 block text-[10px] uppercase">房間數</label>
                                            <input v-model.number="hotelForm.number_of_rooms" type="number" min="1" class="binary-input w-full" />
                                        </div>
                                        <div>
                                            <label class="binary-label mb-1 block text-[10px] uppercase">入住日</label>
                                            <input v-model="hotelForm.check_in_date" type="date" class="binary-input w-full" />
                                        </div>
                                        <div>
                                            <label class="binary-label mb-1 block text-[10px] uppercase">退房日</label>
                                            <input v-model="hotelForm.check_out_date" type="date" class="binary-input w-full" />
                                        </div>
                                        <div>
                                            <label class="binary-label mb-1 block text-[10px] uppercase">每晚成本</label>
                                            <input v-model.number="hotelForm.cost_price_per_night" type="number" min="0" class="binary-input w-full" />
                                        </div>
                                    </div>
                                    <p v-if="hotelFormError" class="mt-2 text-xs text-red-400">{{ hotelFormError }}</p>
                                    <button class="binary-button mt-3" :disabled="hotelSubmitting" @click="addHotel">
                                        {{ hotelSubmitting ? '新增中⋯' : '+ 新增飯店' }}
                                    </button>
                                </template>
                                <p v-else class="text-xs text-[var(--binary-text-muted)]">
                                    已涵蓋全程住宿（{{ editingTour?.duration }} 晚），如需調整請先刪除
                                </p>
                            </div>

                        </div>

                    </div>

                </div>

            </section>
        </main>
    </AppLayout>
</template>

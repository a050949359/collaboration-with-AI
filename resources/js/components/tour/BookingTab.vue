<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { tourFetch } from '@/lib/tour-api';
import type { Tour } from '@/lib/tour-api';

const props = defineProps<{ active: boolean }>();
const emit = defineEmits<{ 'stats-reload': [] }>();

const { t } = useI18n();

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

watch(
    () => bookingForm.value.number_of_travelers,
    (n) => {
        const count = Math.max(0, n - 1);
        const cur = bookingForm.value.companions;
        const curNames = companionNames.value;
        const curErrors = companionErrors.value;
        bookingForm.value.companions = Array.from(
            { length: count },
            (_, i) => cur[i] ?? '',
        );
        companionNames.value = Array.from(
            { length: count },
            (_, i) => curNames[i] ?? '',
        );
        companionErrors.value = Array.from(
            { length: count },
            (_, i) => curErrors[i] ?? '',
        );
    },
);

async function lookupPassenger(
    email: string,
): Promise<{ id: number; email: string } | null> {
    if (!email) {
        return null;
    }

    try {
        return await tourFetch<{ id: number; email: string }>(
            `/passengers/lookup?email=${encodeURIComponent(email)}`,
        );
    } catch {
        return null;
    }
}

async function lookupBooker() {
    if (!bookerName.value) {
        bookingForm.value.passenger_id = '';
        bookerError.value = '';

        return;
    }

    const p = await lookupPassenger(bookerName.value);

    if (p) {
        bookingForm.value.passenger_id = String(p.id);
        bookerError.value = '';
    } else {
        bookingForm.value.passenger_id = '';
        bookerError.value = t('tour_playground.booking.not_registered');
    }
}

async function lookupCompanion(i: number) {
    const email = companionNames.value[i];

    if (!email) {
        bookingForm.value.companions[i] = '';
        companionErrors.value[i] = '';

        return;
    }

    const p = await lookupPassenger(email);

    if (p) {
        bookingForm.value.companions[i] = String(p.id);
        companionErrors.value[i] = '';
    } else {
        bookingForm.value.companions[i] = '';
        companionErrors.value[i] = t('tour_playground.booking.not_registered');
    }
}

async function randomizeBooker() {
    try {
        const p = await tourFetch<{ id: number; email: string }>(
            '/passengers/random',
        );
        bookingForm.value.passenger_id = String(p.id);
        bookerName.value = p.email;
        bookerError.value = '';
    } catch {
        /* ignore */
    }
}

async function randomizeCompanion(i: number) {
    try {
        const p = await tourFetch<{ id: number; email: string }>(
            '/passengers/random',
        );
        bookingForm.value.companions[i] = String(p.id);
        companionNames.value[i] = p.email;
        companionErrors.value[i] = '';
    } catch {
        /* ignore */
    }
}

const newPassengerTarget = ref<'booker' | number | null>(null);
const newPassengerForm = ref({ name: '', phone: '' });
const newPassengerLoading = ref(false);
const newPassengerError = ref('');

async function createPassenger(target: 'booker' | number) {
    const email =
        target === 'booker'
            ? bookerName.value
            : companionNames.value[target as number];
    newPassengerLoading.value = true;
    newPassengerError.value = '';

    try {
        const p = await tourFetch<{ id: number; email: string }>(
            '/passengers',
            {
                method: 'POST',
                body: JSON.stringify({
                    email,
                    name: newPassengerForm.value.name,
                    phone: newPassengerForm.value.phone,
                }),
            },
        );

        if (target === 'booker') {
            bookingForm.value.passenger_id = String(p.id);
            bookerError.value = '';
        } else {
            bookingForm.value.companions[target as number] = String(p.id);
            companionErrors.value[target as number] = '';
        }

        newPassengerForm.value = { name: '', phone: '' };
        newPassengerTarget.value = null;
        emit('stats-reload');
    } catch (e) {
        newPassengerError.value =
            e instanceof Error
                ? e.message
                : t('tour_playground.booking.add_failed');
    } finally {
        newPassengerLoading.value = false;
    }
}

const bookingLoading = ref(false);
const bookingError = ref('');
const bookingSuccess = ref('');

const finalAmount = computed(() =>
    Math.max(
        0,
        (selectedTour.value?.selling_price ?? 0) -
            bookingForm.value.discount_amount,
    ),
);

const passengerConflicts = computed(() => {
    const bookerId = bookingForm.value.passenger_id;

    return bookingForm.value.companions
        .map((id, i) => {
            if (id && id === bookerId) {
                return t('tour_playground.booking.conflict_same_booker', {
                    n: i + 1,
                });
            }

            const dupIdx = bookingForm.value.companions.findIndex(
                (c, j) => j < i && c === id && id !== '',
            );

            if (dupIdx !== -1) {
                return t('tour_playground.booking.conflict_duplicate', {
                    n: i + 1,
                    m: dupIdx + 1,
                });
            }

            return null;
        })
        .filter(Boolean);
});

async function loadTours() {
    toursLoading.value = true;

    try {
        tours.value = await tourFetch<Tour[]>(
            hasVacancy.value ? '/tours?has_vacancy=1' : '/tours',
        );
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
    setTimeout(() => {
        tourListRef.value
            ?.querySelector<HTMLElement>(`[data-tour-id="${tour.id}"]`)
            ?.scrollIntoView({ block: 'nearest' });
    }, 310);
}

async function submitBooking() {
    if (!selectedTour.value || bookingLoading.value) {
        return;
    }

    bookingLoading.value = true;
    bookingError.value = '';
    bookingSuccess.value = '';

    try {
        const companions = bookingForm.value.companions
            .map((s) => parseInt(s))
            .filter((n) => !isNaN(n) && n > 0);
        await tourFetch('/bookings', {
            method: 'POST',
            body: JSON.stringify({
                tour_id: selectedTour.value.id,
                passenger_id: parseInt(bookingForm.value.passenger_id),
                number_of_travelers: bookingForm.value.number_of_travelers,
                discount_amount: bookingForm.value.discount_amount,
                final_amount: finalAmount.value,
                ...(companions.length && { companions }),
                ...(bookingForm.value.remarks && {
                    remarks: bookingForm.value.remarks,
                }),
            }),
        });
        bookingSuccess.value = t('tour_playground.booking.create_success');
        bookingForm.value = {
            passenger_id: '',
            companions: [],
            number_of_travelers: 1,
            discount_amount: 0,
            remarks: '',
        };
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
        bookingError.value =
            e instanceof Error
                ? e.message
                : t('tour_playground.booking.create_failed');
    } finally {
        bookingLoading.value = false;
    }
}

watch(
    () => props.active,
    (isActive) => {
        if (isActive && !tours.value.length) {
            loadTours();
        }
    },
);
</script>

<template>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- Tour List -->
        <div class="binary-card-raised">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-sm font-bold tracking-wide uppercase">
                    {{ t('tour_playground.booking.select_tour') }}
                </h2>
                <label
                    class="flex cursor-pointer items-center gap-1.5 text-xs text-[var(--binary-text-muted)]"
                >
                    <input
                        type="checkbox"
                        v-model="hasVacancy"
                        @change="loadTours"
                        class="accent-[var(--binary-primary)]"
                    />
                    {{ t('tour_playground.booking.vacancy_only') }}
                </label>
            </div>
            <p
                v-if="toursLoading"
                class="py-6 text-center text-sm text-[var(--binary-text-muted)]"
            >
                {{ t('common.loading') }}
            </p>
            <div
                ref="tourListRef"
                class="space-y-2 overflow-y-auto pr-1 transition-all duration-300"
                :class="
                    selectedTour ? 'max-h-20 lg:max-h-[480px]' : 'max-h-[480px]'
                "
            >
                <button
                    v-for="tour in tours"
                    :key="tour.id"
                    :data-tour-id="tour.id"
                    class="w-full rounded-lg border p-3 text-left transition-colors"
                    :class="
                        selectedTour?.id === tour.id
                            ? 'border-[var(--binary-primary)] bg-[var(--binary-primary)]/10'
                            : 'border-[var(--binary-outline)]/20 hover:border-[var(--binary-outline)]/50'
                    "
                    @click="selectTour(tour)"
                >
                    <div class="flex items-center justify-between">
                        <span
                            class="font-mono text-xs text-[var(--binary-text-muted)]"
                            >{{ tour.code }}</span
                        >
                        <div class="flex items-center gap-2">
                            <span
                                class="rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                                :class="
                                    tour.is_formed
                                        ? 'bg-green-500/15 text-green-400'
                                        : 'bg-yellow-500/15 text-yellow-400'
                                "
                                >{{
                                    tour.is_formed
                                        ? t('tour_playground.booking.formed')
                                        : t(
                                              'tour_playground.booking.not_formed',
                                          )
                                }}</span
                            >
                            <span
                                v-if="(tour.booked_pax ?? 0) > tour.max_pax"
                                class="rounded-full bg-red-500/15 px-1.5 py-0.5 text-[10px] font-medium text-red-400"
                            >
                                {{
                                    t('tour_playground.booking.overbooked', {
                                        n:
                                            (tour.booked_pax ?? 0) -
                                            tour.max_pax,
                                    })
                                }}
                            </span>
                            <span
                                class="text-xs"
                                :class="
                                    (tour.booked_pax ?? 0) >= tour.max_pax
                                        ? 'text-red-400'
                                        : 'text-[var(--binary-text-muted)]'
                                "
                            >
                                {{ tour.booked_pax ?? 0 }} / {{ tour.max_pax }}
                                {{ t('tour_playground.pax_unit') }}
                            </span>
                        </div>
                    </div>
                    <p class="mt-0.5 text-sm font-medium">{{ tour.name }}</p>
                    <p class="mt-0.5 text-xs text-[var(--binary-text-muted)]">
                        {{ tour.departure_date }} ｜ {{ tour.duration }}
                        {{ t('tour_playground.days_unit') }} ｜
                        <span class="text-[var(--binary-primary)]"
                            >NT$
                            {{
                                Number(tour.selling_price).toLocaleString()
                            }}</span
                        >
                    </p>
                </button>
            </div>
        </div>

        <!-- Booking Form -->
        <div class="binary-card-raised">
            <h2 class="mb-4 text-sm font-bold tracking-wide uppercase">
                {{ t('tour_playground.booking.booking_info') }}
            </h2>

            <p
                v-if="!selectedTour"
                class="py-10 text-center text-sm text-[var(--binary-text-muted)]"
            >
                {{ t('tour_playground.booking.select_hint') }}
            </p>

            <template v-else>
                <div
                    class="mb-5 rounded-lg bg-[var(--binary-primary)]/10 px-3 py-2.5"
                >
                    <p class="text-sm font-medium">{{ selectedTour.name }}</p>
                    <p
                        class="mt-0.5 font-mono text-xs text-[var(--binary-text-muted)]"
                    >
                        {{ selectedTour.code }}
                    </p>
                </div>

                <div class="space-y-4">
                    <!-- 付款人 -->
                    <div>
                        <label
                            class="binary-label mb-1 block text-[10px] uppercase"
                            >{{
                                t('tour_playground.booking.label_booker')
                            }}</label
                        >
                        <div class="flex gap-2">
                            <input
                                v-model="bookerName"
                                type="email"
                                :placeholder="
                                    t(
                                        'tour_playground.booking.email_placeholder',
                                    )
                                "
                                class="binary-input flex-1"
                                @blur="lookupBooker"
                            />
                            <button
                                class="binary-ghost-button px-3 text-xs"
                                @click="randomizeBooker"
                            >
                                {{ t('tour_playground.booking.random') }}
                            </button>
                        </div>
                        <div v-if="bookerError" class="mt-2 space-y-2">
                            <p class="text-xs text-yellow-400">
                                ⚠ {{ bookerError }}
                                <button
                                    class="ml-2 text-xs underline hover:text-[var(--binary-primary)]"
                                    @click="newPassengerTarget = 'booker'"
                                >
                                    {{
                                        t(
                                            'tour_playground.booking.add_passenger',
                                        )
                                    }}
                                </button>
                            </p>
                            <div
                                v-if="newPassengerTarget === 'booker'"
                                class="space-y-2 rounded-lg border border-[var(--binary-outline)]/20 p-3"
                            >
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label
                                            class="binary-label mb-1 block text-[10px] uppercase"
                                            >{{
                                                t(
                                                    'tour_playground.booking.label_name',
                                                )
                                            }}</label
                                        >
                                        <input
                                            v-model="newPassengerForm.name"
                                            type="text"
                                            :placeholder="
                                                t(
                                                    'tour_playground.booking.placeholder_name',
                                                )
                                            "
                                            class="binary-input w-full"
                                        />
                                    </div>
                                    <div>
                                        <label
                                            class="binary-label mb-1 block text-[10px] uppercase"
                                            >{{
                                                t(
                                                    'tour_playground.booking.label_phone',
                                                )
                                            }}</label
                                        >
                                        <input
                                            v-model="newPassengerForm.phone"
                                            type="tel"
                                            :placeholder="
                                                t(
                                                    'tour_playground.booking.placeholder_phone',
                                                )
                                            "
                                            class="binary-input w-full"
                                        />
                                    </div>
                                </div>
                                <p
                                    v-if="newPassengerError"
                                    class="text-xs text-red-400"
                                >
                                    {{ newPassengerError }}
                                </p>
                                <div class="flex gap-2">
                                    <button
                                        class="binary-button px-3 py-1 text-xs"
                                        :disabled="newPassengerLoading"
                                        @click="createPassenger('booker')"
                                    >
                                        {{
                                            newPassengerLoading
                                                ? t(
                                                      'tour_playground.booking.adding',
                                                  )
                                                : t(
                                                      'tour_playground.booking.confirm_add',
                                                  )
                                        }}
                                    </button>
                                    <button
                                        class="binary-ghost-button px-3 py-1 text-xs"
                                        @click="newPassengerTarget = null"
                                    >
                                        {{
                                            t('tour_playground.booking.cancel')
                                        }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 旅行人數 + 同行人 -->
                    <div class="grid grid-cols-6 gap-3">
                        <div class="col-span-1">
                            <label
                                class="binary-label mb-1 block text-[10px] uppercase"
                                >{{
                                    t('tour_playground.booking.label_travelers')
                                }}</label
                            >
                            <input
                                v-model.number="bookingForm.number_of_travelers"
                                type="number"
                                min="1"
                                class="binary-input w-full"
                            />
                        </div>
                        <div
                            v-for="(_, i) in bookingForm.companions"
                            :key="i"
                            :class="
                                i === 0
                                    ? 'col-span-5'
                                    : 'col-span-5 col-start-2'
                            "
                        >
                            <label
                                class="binary-label mb-1 block text-[10px] uppercase"
                                >{{
                                    t('tour_playground.booking.companion_n', {
                                        n: i + 1,
                                    })
                                }}</label
                            >
                            <div class="flex gap-2">
                                <input
                                    v-model="companionNames[i]"
                                    type="email"
                                    :placeholder="
                                        t(
                                            'tour_playground.booking.email_placeholder',
                                        )
                                    "
                                    class="binary-input flex-1"
                                    @blur="lookupCompanion(i)"
                                />
                                <button
                                    class="binary-ghost-button px-3 text-xs"
                                    @click="randomizeCompanion(i)"
                                >
                                    {{ t('tour_playground.booking.random') }}
                                </button>
                            </div>
                            <div
                                v-if="companionErrors[i]"
                                class="mt-2 space-y-2"
                            >
                                <p class="text-xs text-yellow-400">
                                    ⚠ {{ companionErrors[i] }}
                                    <button
                                        class="ml-2 text-xs underline hover:text-[var(--binary-primary)]"
                                        @click="newPassengerTarget = i"
                                    >
                                        {{
                                            t(
                                                'tour_playground.booking.add_passenger',
                                            )
                                        }}
                                    </button>
                                </p>
                                <div
                                    v-if="newPassengerTarget === i"
                                    class="space-y-2 rounded-lg border border-[var(--binary-outline)]/20 p-3"
                                >
                                    <div class="grid grid-cols-2 gap-2">
                                        <div>
                                            <label
                                                class="binary-label mb-1 block text-[10px] uppercase"
                                                >{{
                                                    t(
                                                        'tour_playground.booking.label_name',
                                                    )
                                                }}</label
                                            >
                                            <input
                                                v-model="newPassengerForm.name"
                                                type="text"
                                                :placeholder="
                                                    t(
                                                        'tour_playground.booking.placeholder_name',
                                                    )
                                                "
                                                class="binary-input w-full"
                                            />
                                        </div>
                                        <div>
                                            <label
                                                class="binary-label mb-1 block text-[10px] uppercase"
                                                >{{
                                                    t(
                                                        'tour_playground.booking.label_phone',
                                                    )
                                                }}</label
                                            >
                                            <input
                                                v-model="newPassengerForm.phone"
                                                type="tel"
                                                :placeholder="
                                                    t(
                                                        'tour_playground.booking.placeholder_phone',
                                                    )
                                                "
                                                class="binary-input w-full"
                                            />
                                        </div>
                                    </div>
                                    <p
                                        v-if="newPassengerError"
                                        class="text-xs text-red-400"
                                    >
                                        {{ newPassengerError }}
                                    </p>
                                    <div class="flex gap-2">
                                        <button
                                            class="binary-button px-3 py-1 text-xs"
                                            :disabled="newPassengerLoading"
                                            @click="createPassenger(i)"
                                        >
                                            {{
                                                newPassengerLoading
                                                    ? t(
                                                          'tour_playground.booking.adding',
                                                      )
                                                    : t(
                                                          'tour_playground.booking.confirm_add',
                                                      )
                                            }}
                                        </button>
                                        <button
                                            class="binary-ghost-button px-3 py-1 text-xs"
                                            @click="newPassengerTarget = null"
                                        >
                                            {{
                                                t(
                                                    'tour_playground.booking.cancel',
                                                )
                                            }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label
                            class="binary-label mb-1 block text-[10px] uppercase"
                            >{{
                                t('tour_playground.booking.label_discount')
                            }}</label
                        >
                        <input
                            v-model.number="bookingForm.discount_amount"
                            type="number"
                            min="0"
                            class="binary-input w-full"
                        />
                    </div>

                    <div>
                        <label
                            class="binary-label mb-1 block text-[10px] uppercase"
                            >{{
                                t('tour_playground.booking.label_remarks')
                            }}</label
                        >
                        <input
                            v-model="bookingForm.remarks"
                            type="text"
                            class="binary-input w-full"
                        />
                    </div>

                    <div
                        class="rounded-lg border border-[var(--binary-outline)]/20 px-4 py-3"
                    >
                        <p
                            class="text-[10px] text-[var(--binary-text-muted)] uppercase"
                        >
                            {{ t('tour_playground.booking.due_amount') }}
                        </p>
                        <p
                            class="text-2xl font-bold text-[var(--binary-primary)]"
                        >
                            NT$ {{ finalAmount.toLocaleString() }}
                        </p>
                    </div>

                    <div
                        class="rounded-lg border border-dashed border-[var(--binary-outline)]/30 px-4 py-3 text-center text-xs text-[var(--binary-text-muted)]"
                    >
                        {{ t('tour_playground.booking.payment_placeholder') }}
                    </div>

                    <div v-if="passengerConflicts.length" class="space-y-1">
                        <p
                            v-for="msg in passengerConflicts"
                            :key="msg"
                            class="text-xs text-yellow-400"
                        >
                            ⚠ {{ msg }}
                        </p>
                    </div>

                    <p v-if="bookingError" class="text-xs text-red-400">
                        {{ bookingError }}
                    </p>
                    <p
                        v-if="bookingSuccess"
                        class="text-xs text-[var(--binary-primary)]"
                    >
                        {{ bookingSuccess }}
                    </p>

                    <button
                        class="binary-button w-full"
                        :class="{
                            'cursor-not-allowed opacity-50':
                                bookingLoading || passengerConflicts.length > 0,
                        }"
                        :disabled="
                            bookingLoading || passengerConflicts.length > 0
                        "
                        @click="submitBooking"
                    >
                        {{
                            bookingLoading
                                ? t('tour_playground.booking.submitting')
                                : t('tour_playground.booking.submit')
                        }}
                    </button>
                </div>
            </template>
        </div>
    </div>
</template>

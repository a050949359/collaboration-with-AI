export type Passenger = {
    id: number;
    name: string;
    email: string;
    phone: string;
    bookings?: Booking[];
    companion_of?: Booking[];
};

export type Tour = {
    id: number;
    code: string;
    name: string;
    type: string;
    duration: number;
    departure_date: string;
    return_date: string;
    selling_price: number;
    min_pax: number;
    max_pax: number;
    booked_pax?: number;
    is_formed?: boolean;
    remarks: string | null;
};

export type Booking = {
    id: number;
    booking_reference: string;
    tour_id: number;
    passenger_id: number;
    status: string;
    number_of_travelers: number;
    discount_amount: number;
    final_amount: number;
    tour?: Tour;
    passenger?: Passenger;
    payments?: Payment[];
};

export type Payment = { id: number; amount: number };

export type ExportTask = {
    id: number;
    type: string;
    params: string;
    status: string;
    file_path: string | null;
    created_at: string;
};

export type Airport = {
    id: number;
    ident: string;
    name: string;
    iata_code: string | null;
    municipality: string | null;
};

export type TourFlight = {
    id: number;
    flight_number: string;
    cabin_class: string;
    origin_airport_id: number;
    destination_airport_id: number;
    departure_time: string;
    arrival_time: string;
    cost_price: number;
    remarks: string | null;
};

export type TourHotel = {
    id: number;
    hotel_name: string;
    room_type: string;
    check_in_date: string;
    check_out_date: string;
    number_of_rooms: number;
    nights: number;
    cost_price_per_night: number;
    total_cost_price: number;
    remarks: string | null;
};

export async function tourFetch<T>(
    path: string,
    init?: RequestInit,
): Promise<T> {
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

        throw new Error(
            (err as { message?: string }).message ?? `Error ${res.status}`,
        );
    }

    if (res.status === 204) {
        return null as T;
    }

    return res.json();
}

export function safeParseParams(params: string): Record<string, string> {
    try {
        return JSON.parse(params);
    } catch {
        return {};
    }
}

export interface FactoryProps {
    id: number;
    name: string;
    slug: string;
    phone: string | null;
    logo: string | null;
}

export interface DriverProps {
    id: number;
    name: string | null;
    mobile: string;
}

export interface UserProps {
    id: number;
    name: string;
    email: string;
    roles: string[];
    permissions: string[];
}

export interface OtpFlash {
    mobile: string;
    resend_in: number;
    expires_in: number;
    dev_code: string | null;
}

export interface PageProps {
    auth: { user: UserProps | null; driver: DriverProps | null };
    factory: FactoryProps | null;
    flash: { success: string | null; error: string | null; otp: OtpFlash | null };
    errors: Record<string, string>;
    [key: string]: unknown;
}

export interface PlateParts {
    two: string;
    letter: string;
    three: string;
    iran: string;
    key: string;
    short: string;
    full: string;
}

export type StatusTone =
    | 'booked'
    | 'waiting'
    | 'called'
    | 'checkedin'
    | 'loading'
    | 'loaded'
    | 'completed'
    | 'failed';

export interface Appointment {
    ulid: string;
    number: number;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    is_active: boolean;
    date: string;
    jalali_date: string;
    jalali_long: string;
    time: string;
    end_time: string;
    product?: { id: number; name: string; load_tons: string | null };
    truck?: { id: number; plate: PlateParts; type: string | null };
    driver?: { id: number; name: string | null; mobile: string };
    loading_point?: string | null;
    checked_in_at: string | null;
    called_at: string | null;
    loading_started_at: string | null;
    completed_at: string | null;
    cancel_reason: string | null;
    cancelled_by: 'driver' | 'staff' | 'system' | null;
    cancelled_by_label: string | null;
    created_at: string | null;
    is_today?: boolean;
    ahead?: number | null;
    eta_minutes?: number | null;
    priority?: number;
    priority_reason?: string | null;
    schedule?: {
        loading_minutes: number;
        queue_minutes: number;
        starts_at: string;
        ends_at: string;
    } | null;
}

export interface SlotOption {
    id: number;
    start_time: string;
    end_time: string;
    capacity: number;
    reserved: number;
    remaining: number;
    selectable: boolean;
}

export interface DayOption {
    date: string;
    jalali: string;
    jalali_label: string;
    is_today: boolean;
    is_open: boolean;
    remaining: number;
}

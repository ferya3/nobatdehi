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
    /** تعداد رقم‌های کد — از config('otp.length') سرور می‌آید */
    length?: number;
}

export interface PageProps {
    auth: { user: UserProps | null; driver: DriverProps | null };
    factory: FactoryProps | null;
    flash: { success: string | null; error: string | null; warning: string | null; otp: OtpFlash | null };
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

/** یک خواندنِ پلاک — از دوربین پلاک‌خوان شبکه‌ای یا دوربین ایستگاه */
export interface PlateReading {
    id: number;
    source: 'anpr' | 'station';
    source_label: string;
    plate_key: string | null;
    raw_plate: string | null;
    confidence: number | null;
    lane: string | null;
    /** دستگاه توانست پلاک را بخواند؟ اگر نه، تطبیق با نگهبان است */
    recognised: boolean;
    image_url: string | null;
    captured_at: string | null;
    clock: string | null;
}

/** آخرین عددِ یک باسکول، همان‌طور که صفحه‌ی باسکول می‌بیندش */
export interface ScaleLive {
    id: number;
    scale: string;
    weight_kg: number;
    /** عقربه آرام گرفته؟ عددِ ناپایدار ثبت نمی‌شود */
    is_stable: boolean;
    read_at: string | null;
    clock: string | null;
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
        starts_in_minutes: number;
        starts_at: string;
        ends_at: string;
    } | null;
    gate?: {
        entry_method: string | null;
        entry_method_label: string | null;
        scan_source: string | null;
        scan_source_label: string | null;
        observed_plate: string | null;
        plate_source: string | null;
        plate_source_label: string | null;
        override_reason: string | null;
        reading: PlateReading | null;
    } | null;
}

/**
 * نوبتی که سامانه اعلام می‌کند.
 *
 * راننده انتخابش نمی‌کند — می‌بیند. جای SlotOption و DayOption را گرفت،
 * که وقتی انتخابی در کار نیست، دیگر معنایی ندارند.
 */
export interface OpeningOption {
    date: string;
    jalali: string;
    jalali_long: string;
    day_label: string;
    is_today: boolean;
    starts_at: string;
    ends_at: string;
    loading_minutes: number;
}

export interface TruckTypeOption {
    id: number;
    name: string;
    capacity_tons: string | null;
    loading_minutes: number;
    /** null یعنی تا انتهای افق نوبت‌دهی برای این خودرو جا نیست */
    opening: OpeningOption | null;
}

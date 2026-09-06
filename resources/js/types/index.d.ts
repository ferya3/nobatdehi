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

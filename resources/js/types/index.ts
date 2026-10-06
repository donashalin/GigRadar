export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
}

export interface SharedData {
    name: string;
    auth: Auth;
    vapidPublicKey?: string | null;
    flash?: { error?: string | null; success?: string | null };
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    home_location_name: string | null;
    home_lat: number | null;
    home_lng: number | null;
    home_country_code: string | null;
    radius_miles: number;
    nearby_mode: 'country' | 'radius';
    notify_email: boolean;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;

export interface User {
    id: number;
    name: string | null;
    role: 'subscriber' | 'super_admin';
}

export interface Access {
    state: 'preview' | 'trialing' | 'active' | 'expired' | 'cancelled';
    plan_code: string | null;
    active_query_limit: number | null;
    ends_at: string | null;
}

export type PageProps<T extends Record<string, unknown> = Record<string, unknown>> =
    T & {
        auth: {
            user: User | null;
            access: Access | null;
        };
    };

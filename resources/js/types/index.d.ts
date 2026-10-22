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
    mode: 'developer' | 'owner' | null;
}

// Only the external sales channel may eventually receive a populated catalog.
// The Mini App currently receives `unavailable` and no checkout or offers.
export type MiniAppPurchaseState = {
    availability: 'unavailable';
    plans: [];
    checkout: null;
};

export type ExternalPurchaseState = {
    availability: 'external';
    plans: PurchasePlan[];
    checkout: ExternalCheckout | null;
};

export interface PurchasePlan {
    code: string;
    name: string;
    description: string;
    amount_minor: number;
    currency: 'RUB';
    duration_days: number;
    active_query_limit: number | null;
    terms_url: string;
}

export type ExternalCheckout =
    | { state: 'preparing'; order_id: string }
    | { state: 'awaiting_payment'; order_id: string; confirmation_url: string }
    | { state: 'canceled' | 'failed'; order_id: string; message: string }
    | { state: 'access_pending'; order_id: string; message: string }
    | {
          state: 'succeeded';
          order_id: string;
          plan_name: string;
          workspace_url: string;
          access: Access & { state: 'active' };
      };

export type PageProps<T extends Record<string, unknown> = Record<string, unknown>> =
    T & {
        auth: {
            user: User | null;
            access: Access | null;
        };
    };

export type AnalyticsTier = 'basic' | 'pro' | 'ultra';
export type TenderInsights = {
    tier: AnalyticsTier;
    generated_at: string;
    pro?: {
        priority: 'conflict' | 'unknown' | 'elapsed' | 'urgent' | 'soon' | 'normal';
        window_hours: number | null;
        buffer_at: string | null;
        checks: { code: string; label: string; present: boolean }[];
        known_fields: number;
        total_fields: number;
        completeness_percent: number;
        signals: { tone: 'neutral' | 'warning'; title: string; detail: string }[];
        actions: string[];
    };
    ultra?: {
        comparison: {
            window_days: number;
            candidate_limit: number;
            truncated: boolean;
            sample_count: number;
            minimum_sample: number;
            currency: string;
            median: string | null;
            lower_quartile: string | null;
            upper_quartile: string | null;
            difference_percent: number | null;
            scope: 'personal_monitorings' | 'shared_monitorings';
        };
        changes: {
            items: {
                at: string | null;
                fields: {
                    field: string;
                    before: string | null;
                    after: string | null;
                }[];
            }[];
            has_more: boolean;
        };
        readiness: {
            started: boolean;
            stage: string | null;
            tasks_total: number;
            tasks_completed: number;
            tasks_overdue: number;
            completion_percent: number | null;
            scope: 'personal' | 'team';
        };
    };
};

// Empty input is unknown, not a free execution scenario. Work in whole kopecks.
export function scenarioMoney(value: string): number | null {
    const text = value.trim().replace(/\s/g, '').replace(',', '.');
    if (!/^\d+(?:\.\d{1,2})?$/.test(text)) return null;
    const amount = Number(text);
    return Number.isFinite(amount) && amount >= 0 && amount <= 1_000_000_000_000
        ? Math.round(amount * 100)
        : null;
}

export function priceScenario(budget: string | null, cost: string, discount: number) {
    const budgetCents = budget === null ? null : scenarioMoney(budget);
    const costCents = scenarioMoney(cost);
    if (
        budgetCents === null ||
        budgetCents <= 0 ||
        !Number.isFinite(discount) ||
        discount < 0 ||
        discount > 100
    ) {
        return null;
    }
    const discountBasisPoints = Math.round(discount * 100);
    const offerCents = Number(
        (BigInt(budgetCents) * BigInt(10_000 - discountBasisPoints) + 5_000n) / 10_000n,
    );
    const balanceCents = costCents === null ? null : offerCents - costCents;
    return {
        offer: (offerCents / 100).toFixed(2),
        balance: balanceCents === null ? null : (balanceCents / 100).toFixed(2),
        marginPercent:
            balanceCents === null || offerCents === 0
                ? null
                : Math.round((balanceCents / offerCents) * 1000) / 10,
        breakEvenDiscount:
            costCents === null
                ? null
                : Math.floor(((budgetCents - costCents) * 1000) / budgetCents) / 10,
    };
}

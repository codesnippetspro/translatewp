// The product plan names, matching the user portal. The plugin reads only the
// plan tier from its connection state; a billing cycle is passed only when the
// surface genuinely knows it, so a cycle is never claimed when it is unknown.
export type PlanBillingCycle = 'monthly' | 'yearly' | 'oneoff';

export type PlanEntitlement = { plan: 'free' | 'pro'; billingCycle?: PlanBillingCycle | null };

export const PLAN_NAMES = { free: 'Terrestrial', pro: 'Orbital' } as const;

export const ORBITAL_CYCLE_NAMES: Record<PlanBillingCycle, string> = {
    monthly: 'Orbital monthly',
    yearly: 'Orbital yearly',
    oneoff: 'Orbital lifetime',
};

export const planLabel = (entitlement: PlanEntitlement): string =>
{
    if (entitlement.plan === 'free') {
        return PLAN_NAMES.free;
    }

    return entitlement.billingCycle ? ORBITAL_CYCLE_NAMES[entitlement.billingCycle] : PLAN_NAMES.pro;
};

import { act } from 'react';
import { createRoot } from 'react-dom/client';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

import { PlanPill } from '@/shared/components/PlanPill';
import { planLabel } from '@/shared/lib/plan/planLabel';

const render = (node: React.ReactElement) =>
{
    const container = document.createElement('div');
    const root = createRoot(container);
    act(() => root.render(node));
    return { container, root };
};

describe('planLabel', () =>
{
    it('labels the free plan Terrestrial', () =>
    {
        expect(planLabel({ plan: 'free' })).toBe('Terrestrial');
    });

    it('labels a paid plan without a known cycle Orbital', () =>
    {
        expect(planLabel({ plan: 'pro' })).toBe('Orbital');
        expect(planLabel({ plan: 'pro', billingCycle: null })).toBe('Orbital');
    });

    it('names the billing cycle only when the cycle is known', () =>
    {
        expect(planLabel({ plan: 'pro', billingCycle: 'monthly' })).toBe('Orbital monthly');
        expect(planLabel({ plan: 'pro', billingCycle: 'yearly' })).toBe('Orbital yearly');
        expect(planLabel({ plan: 'pro', billingCycle: 'oneoff' })).toBe('Orbital lifetime');
    });
});

describe('PlanPill', () =>
{
    it('frames a paid plan in the primary tone with the plan name', () =>
    {
        const { container, root } = render(<PlanPill entitlement={{ plan: 'pro' }} />);
        const pill = container.querySelector('[data-plan-chip]') as HTMLElement;

        expect(pill.tagName).toBe('SPAN');
        expect(pill.className).toContain('border-twp-primary-500');
        expect(pill.className).toContain('bg-twp-primary-500/5');
        expect(pill.className).toContain('rounded-full');
        expect(pill.textContent).toContain('Current plan');
        expect(pill.textContent).toContain('Orbital');
        act(() => root.unmount());
    });

    it('frames the free plan in the neutral tone', () =>
    {
        const { container, root } = render(<PlanPill entitlement={{ plan: 'free' }} />);
        const pill = container.querySelector('[data-plan-chip]') as HTMLElement;

        expect(pill.className).toContain('border-twp-neutral-200');
        expect(pill.className).toContain('bg-twp-neutral-0');
        expect(pill.textContent).toContain('Terrestrial');
        act(() => root.unmount());
    });

    it('never wraps its label: the chip and its spans are whitespace-nowrap, shrink-0', () =>
    {
        const { container, root } = render(<PlanPill entitlement={{ plan: 'pro', billingCycle: 'oneoff' }} />);
        const pill = container.querySelector('[data-plan-chip]') as HTMLElement;

        expect(pill.className).toContain('whitespace-nowrap');
        expect(pill.className).toContain('shrink-0');
        const spans = pill.querySelectorAll('span');
        expect(spans.length).toBe(2);
        spans.forEach(span => expect(span.className).toContain('whitespace-nowrap'));
        expect(pill.textContent).toContain('Orbital lifetime');
        act(() => root.unmount());
    });

    it('renders a safe external link when an href is given', () =>
    {
        const { container, root } = render(<PlanPill entitlement={{ plan: 'pro' }} href="https://app.translatewp.ai/account" />);
        const pill = container.querySelector('a[data-plan-chip]') as HTMLAnchorElement;

        expect(pill.getAttribute('href')).toBe('https://app.translatewp.ai/account');
        expect(pill.target).toBe('_blank');
        expect(pill.rel).toContain('noopener');
        act(() => root.unmount());
    });
});

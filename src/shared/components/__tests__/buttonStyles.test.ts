import { TWP_BUTTON_PRIMARY, TWP_BUTTON_SECONDARY, TWP_BUTTON_PRIMARY_BLOCK, TWP_BUTTON_GHOST } from '@/shared/components/buttonStyles';

describe('shared button styles', () =>
{
    it('the primary button carries the portal primary tone, pill radius and states', () =>
    {
        expect(TWP_BUTTON_PRIMARY).toContain('bg-twp-primary-500!');
        expect(TWP_BUTTON_PRIMARY).toContain('rounded-full');
        expect(TWP_BUTTON_PRIMARY).toContain('hover:bg-twp-primary-500/90');
        expect(TWP_BUTTON_PRIMARY).toContain('active:scale-[0.98]');
        expect(TWP_BUTTON_PRIMARY).toContain('focus-visible:outline-twp-primary-500');
        expect(TWP_BUTTON_PRIMARY).toContain('disabled:opacity-60');
    });

    it('the secondary button is the outlined neutral pill', () =>
    {
        expect(TWP_BUTTON_SECONDARY).toContain('border-twp-neutral-200');
        expect(TWP_BUTTON_SECONDARY).toContain('bg-twp-neutral-0');
        expect(TWP_BUTTON_SECONDARY).toContain('rounded-full');
        expect(TWP_BUTTON_SECONDARY).toContain('hover:bg-twp-neutral-100');
    });

    it('the block primary fills its container with the card radius', () =>
    {
        expect(TWP_BUTTON_PRIMARY_BLOCK).toContain('w-full');
        expect(TWP_BUTTON_PRIMARY_BLOCK).toContain('rounded-lg');
        expect(TWP_BUTTON_PRIMARY_BLOCK).toContain('bg-twp-primary-500!');
    });

    it('the ghost button is transparent with a neutral hover', () =>
    {
        expect(TWP_BUTTON_GHOST).toContain('bg-transparent');
        expect(TWP_BUTTON_GHOST).toContain('hover:bg-twp-neutral-100');
    });
});

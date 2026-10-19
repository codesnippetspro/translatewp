import { saveSwitcherPosition, type SaveSwitcherPositionInput } from '@/shared/api/wordpress';

export const switcherPositionMutationOptions = () => ({
	mutationFn: (input: SaveSwitcherPositionInput) => saveSwitcherPosition(input),
	networkMode: 'always' as const,
	retry: false as const,
});

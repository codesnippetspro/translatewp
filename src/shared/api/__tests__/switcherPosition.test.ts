import apiFetch from '@wordpress/api-fetch';
import { saveSwitcherPosition } from '../wordpress';

jest.mock('@wordpress/api-fetch', () => ({ __esModule: true, default: jest.fn() }));

it('saves a position through the nonce-authenticated revision contract', async () => {
	const position = { anchor: 'bottom-right' as const, x: 52, y: 152 };
	const response = { success: true, position, revision: 8 };
	window.TranslateWP = { context: { nonce: 'nonce-1' } } as typeof window.TranslateWP;
	(apiFetch as jest.Mock).mockResolvedValueOnce(response);
	await expect(saveSwitcherPosition({ position, revision: 7 })).resolves.toEqual(response);
	expect(apiFetch).toHaveBeenCalledWith({
		path: '/translatewp/v1/admin/switcher-position', method: 'POST',
		headers: { 'X-WP-Nonce': 'nonce-1' }, data: { position, revision: 7 },
	});
});

it('propagates a rejected save without retrying it', async () => {
	(apiFetch as jest.Mock).mockClear().mockRejectedValueOnce({ code: 'settings_changed' });
	await expect(saveSwitcherPosition({
		position: { anchor: 'top-left', x: 1, y: 2 }, revision: 7,
	})).rejects.toEqual({ code: 'settings_changed' });
	expect(apiFetch).toHaveBeenCalledTimes(1);
});

import { hasDragMovement, positionFromDrop } from '../switcherDrag';

describe('floating switcher drag geometry', () => {
	it('keeps a press below six pixels as a click', () => {
		expect(hasDragMovement({ x: 5, y: 0 })).toBe(false);
		expect(hasDragMovement({ x: 6, y: 0 })).toBe(true);
		expect(hasDragMovement({ x: 4, y: 4 })).toBe(false);
		expect(hasDragMovement({ x: 5, y: 4 })).toBe(true);
	});
	it.each([
		[10, 20, 'top-left', 10, 20],
		[900, 20, 'top-right', 52, 20],
		[10, 600, 'bottom-left', 10, 152],
		[900, 600, 'bottom-right', 52, 152],
		[476, 376, 'bottom-right', 476, 376],
		[-50, -50, 'top-left', 0, 0],
		[2000, 2000, 'bottom-right', 0, 0],
	])('anchors a drop at %i, %i', (left, top, anchor, x, y) => {
		expect(positionFromDrop({ left, top, width: 48, height: 48,
			viewportWidth: 1000, viewportHeight: 800, adminBarHeight: 0 }))
			.toEqual({ anchor, x, y });
	});
	it('stores top offsets below the administrator bar', () => {
		expect(positionFromDrop({ left: 10, top: 52, width: 48, height: 48,
			viewportWidth: 1000, viewportHeight: 800, adminBarHeight: 32 }))
			.toEqual({ anchor: 'top-left', x: 10, y: 20 });
	});
});

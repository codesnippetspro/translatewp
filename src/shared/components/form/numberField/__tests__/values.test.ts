import { clampNumberField, numberFieldBounds, numberFieldDragValue } from '@/shared/components/form/numberField/values';

it('keeps the approved font, metric and retention bounds', () =>
{
    expect(numberFieldBounds).toEqual({
        fontSize: { min: 10, max: 32 },
        switcherMetric: { min: 0, max: 500 },
        cookieDays: { min: 1, max: 365 },
    });
});

it.each([[5, 10], [99, 32], [18.9, 18], [14, 14]])('clamps %s to %s within font bounds', (value, expected) =>
{
    expect(clampNumberField(value, 10, 32)).toBe(expected);
});

it.each([
    [14, 3, 1, 14], [14, -3, 1, 14],
    [14, 4, 1, 15], [14, -4, 1, 13],
    [14, 16, 1, 18], [14, -16, 1, 10],
    [14, 8, 2, 18], [14, 400, 1, 32], [14, -400, 1, 10],
])('drag from %s by %spx with step %s gives %s', (value, delta, step, expected) =>
{
    expect(numberFieldDragValue(value, delta, step, 10, 32)).toBe(expected);
});

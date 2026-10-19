/**
 * Cuts a string to at most `max` UTF-16 units without splitting a surrogate
 * pair: a cut that would leave a lone high surrogate steps back one unit.
 */
export const truncateText = (text: string, max: number): string =>
{
    if (text.length <= max) return text;

    const unit = text.charCodeAt(max - 1);
    const endsOnHighSurrogate = unit >= 0xd800 && unit <= 0xdbff;

    return text.slice(0, endsOnHighSurrogate ? max - 1 : max);
};

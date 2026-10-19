const NUMBER_PLACEHOLDER = '#';

const DIGIT_RUN = /\p{Nd}+/gu;

/** Every run of digits becomes the placeholder. */
export function normalizeNumbers(text: string): string
{
    return text.replace(DIGIT_RUN, NUMBER_PLACEHOLDER);
}

export function digitRuns(text: string): string[]
{
    return text.match(DIGIT_RUN) ?? [];
}

/**
 * Writes the digit runs of `source` into `translated`, in order. Returns null
 * when the two do not carry the same number of runs.
 */
export function substituteNumbers(source: string, translated: string): string | null
{
    const runs = digitRuns(source);
    if (runs.length === 0) return translated;
    if (digitRuns(translated).length !== runs.length) return null;

    let index = 0;
    return translated.replace(DIGIT_RUN, () => runs[index++]);
}

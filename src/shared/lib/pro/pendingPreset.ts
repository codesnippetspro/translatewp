import type { PresetKind } from '@/apps/admin/components/Translations/Style/presetExamples';

const KEY = 'translatewp-pending-pro-preset';
const MAX_AGE_MS = 15 * 60 * 1000;

export interface PendingPreset
{
    kind: PresetKind;
    value: string;
}

/** Remember the locked preset an install or activation was started from, across the reload that follows. */
export const rememberPendingPreset = (preset: PendingPreset): void =>
{
    try {
        window.sessionStorage.setItem(KEY, JSON.stringify({ ...preset, at: Date.now() }));
    } catch {
        // Storage may be unavailable; the choice is then simply not carried over.
    }
};

/** The remembered presets still fresh enough to apply, removed as they are read. */
export const takePendingPreset = (): PendingPreset | null =>
{
    try {
        const raw = window.sessionStorage.getItem(KEY);
        window.sessionStorage.removeItem(KEY);
        if (!raw) return null;
        const parsed = JSON.parse(raw) as Partial<PendingPreset> & { at?: number };
        if ((parsed.kind !== 'style' && parsed.kind !== 'tone') || typeof parsed.value !== 'string') return null;
        if (typeof parsed.at !== 'number' || Date.now() - parsed.at > MAX_AGE_MS) return null;
        return { kind: parsed.kind, value: parsed.value };
    } catch {
        return null;
    }
};

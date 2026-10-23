import { addFilter, applyFilters, doAction } from '@wordpress/hooks';

import { editMode } from '@/apps/translatewp/editMode';
import type { ComponentType } from 'react';

import { CacheFirstTranslator } from '@/shared/lib/translations/CacheFirstTranslator';
import { ContextGrouping } from '@/shared/lib/translations/ContextGrouping';
import type { HydrationStore } from '@/shared/lib/translations/hydrationStore';
import { TranslatableEntry } from '@/shared/lib/translations/TranslatableEntry';
import { TranslationEngine } from '@/shared/lib/translations/TranslationEngine';
import segmentRegistry, {
    applyPayload,
    buildPayload,
    compareSegmentsInDocumentOrder,
    payloadValues,
    segmentElement,
    stripMarkers,
    type AppliedSegment,
} from '@/shared/lib/translations/segmentRegistry';
import {
    cssPath, effectivePlacement,
    resolvePlacementTarget, livePlacement,
    resolveZoneElement,
    SWITCHER_ZONES,
    ZONE_LABELS,
    type InlinePlacement,
} from '@/apps/translatewp/inlinePlacement';

// The public page's bridge for the Pro front-end editor.
// The Pro bundle is a separate module graph, so it must use these instances
// (the page's registry, hydration store and translator factory) rather than
// its own copies.
export const FRONTEND_HOST_BRIDGE_VERSION = 1;

export type FrontendSlotComponent = ComponentType<any> & { hostVersion?: number };

const registerSlot = (name: string, panel: FrontendSlotComponent, namespace = 'translatewp-pro'): void =>
{
    addFilter(`translatewp.frontend.slot.${name}`, namespace, () => panel);
    doAction('translatewp.frontend.slotsChanged', name);
};

export const resolveFrontendSlot = (name: string): FrontendSlotComponent | null =>
{
    const resolved = applyFilters(`translatewp.frontend.slot.${name}`, null) as FrontendSlotComponent | null;
    return typeof resolved === 'function' ? resolved : null;
};

type PageState = {
    hydrationStore: HydrationStore | null;
    defaultLocale: string;
    selectedLocale: string;
    // Re-runs the served translation of the given segments through the cache
    // path (remote first) after a correction was removed or regenerated.
    retranslate: ((segments: AppliedSegment[]) => Promise<void>) | null;
};

const pageState: PageState = { hydrationStore: null, defaultLocale: '', selectedLocale: '', retranslate: null };

const engine = new TranslationEngine();
const writeEntry = (entry: TranslatableEntry): void => engine.apply([entry]);

// Applies a payload to the page and keeps the local hydration copy in step.
const serveSegment = async (segment: AppliedSegment, payload: string): Promise<void> =>
{
    applyPayload(segment, payload, writeEntry);
    await pageState.hydrationStore?.putMany(new Map([[segment.hash, payload]]), segment.language);
};

const forgetSegments = async (segments: AppliedSegment[]): Promise<void> =>
{
    const byLanguage = new Map<string, string[]>();
    for (const segment of segments)
    {
        const list = byLanguage.get(segment.language) ?? [];
        list.push(segment.hash);
        byLanguage.set(segment.language, list);
    }
    for (const [language, hashes] of byLanguage) await pageState.hydrationStore?.deleteMany(hashes, language);
};

export interface FrontendHostBridge
{
    version: number;
    registerSlot: typeof registerSlot;
    segmentRegistry: typeof segmentRegistry;
    segmentElement: typeof segmentElement;
    compareSegmentsInDocumentOrder: typeof compareSegmentsInDocumentOrder;
    stripMarkers: typeof stripMarkers;
    buildPayload: typeof buildPayload;
    payloadValues: typeof payloadValues;
    serveSegment: typeof serveSegment;
    forgetSegments: typeof forgetSegments;
    retranslate: (segments: AppliedSegment[]) => Promise<void>;
    page: () => { defaultLocale: string; selectedLocale: string };
    editMode: typeof editMode;
    placement: {
        zones: typeof SWITCHER_ZONES;
        labels: typeof ZONE_LABELS;
        resolveZoneElement: typeof resolveZoneElement;
        resolvePlacementTarget: typeof resolvePlacementTarget;
        cssPath: typeof cssPath;
        current: () => InlinePlacement | null;
        /** Moves the switcher to a just-saved placement, or back to floating for null, without a reload. */
        apply: (placement: InlinePlacement | null) => void;
    };
    ContextGrouping: typeof ContextGrouping;
    CacheFirstTranslator: typeof CacheFirstTranslator;
}

export const frontendHost: FrontendHostBridge = {
    version: FRONTEND_HOST_BRIDGE_VERSION,
    registerSlot,
    segmentRegistry,
    segmentElement,
    compareSegmentsInDocumentOrder,
    stripMarkers,
    buildPayload,
    payloadValues,
    serveSegment,
    forgetSegments,
    retranslate: async segments =>
    {
        await forgetSegments(segments);
        await pageState.retranslate?.(segments);
    },
    page: () => ({ defaultLocale: pageState.defaultLocale, selectedLocale: pageState.selectedLocale }),
    editMode,
    placement: {
        zones: SWITCHER_ZONES, labels: ZONE_LABELS, resolveZoneElement, resolvePlacementTarget, cssPath,
        current: () =>
        {
            const live = livePlacement.current();
            return live ? live.placement : effectivePlacement(window.TranslateWP?.context?.page_placement,
                window.TranslateWP?.options?.languages?.switcher?.inline_placement);
        },
        apply: placement => livePlacement.apply(placement),
    },
    ContextGrouping,
    CacheFirstTranslator,
};

// The page app reports what the bridge needs as it changes.
export const updateFrontendPageState = (patch: Partial<PageState>): void =>
{
    Object.assign(pageState, patch);
};

export const installFrontendHostBridge = (): void =>
{
    window.TranslateWP = Object.assign(
        window.TranslateWP ?? ({} as Window['TranslateWP']),
        { frontend: frontendHost },
    );
};

export type { AppliedSegment, InlinePlacement };

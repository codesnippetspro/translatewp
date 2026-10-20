import { __ } from '@wordpress/i18n';

export type PresetKind = 'style' | 'tone';

export interface PresetOption
{
    label: string;
    value: string;
    pro?: boolean;
}

// The presets a Terrestrial site may use; the rest are Orbital.
export const FREE_PRESETS: Record<PresetKind, string[]> = {
    style: ['neutral', 'marketing', 'technical'],
    tone: ['neutral', 'friendly', 'professional', 'casual'],
};

// The preset lists live here once so the free control and the Pro panel (which
// renders the same control through the host bridge) never drift apart.
export const PRESET_OPTIONS: Record<PresetKind, PresetOption[]> = {
    style: [
        { label: __('Neutral', 'translate'), value: 'neutral' },
        { label: __('Marketing', 'translate'), value: 'marketing' },
        { label: __('Technical', 'translate'), value: 'technical' },
        { label: __('Legal', 'translate'), value: 'legal', pro: true },
        { label: __('Medical', 'translate'), value: 'medical', pro: true },
    ],
    tone: [
        { label: __('Neutral', 'translate'), value: 'neutral' },
        { label: __('Friendly', 'translate'), value: 'friendly' },
        { label: __('Professional', 'translate'), value: 'professional' },
        { label: __('Casual', 'translate'), value: 'casual' },
        { label: __('Playful', 'translate'), value: 'playful', pro: true },
        { label: __('Luxury', 'translate'), value: 'luxury', pro: true },
    ],
};

// No longer offered, but shown as the chosen preset on a site that saved it,
// until the site picks another.
export const RETIRED_PRESET_OPTIONS: Record<PresetKind, PresetOption[]> = {
    style: [],
    tone: [{ label: __('Formal', 'translate'), value: 'formal' }],
};

export const PRESET_LABELS: Record<PresetKind, string> = {
    style: __('Preset style', 'translate'),
    tone: __('Preset tone', 'translate'),
};

// One static sample line per preset, shown under the control so the owner can
// hear the difference before choosing. Written once and bundled; no request.
export const PRESET_EXAMPLES: Record<PresetKind, Record<string, string>> = {
    style: {
        neutral: __('Our team replies to every message within two working days.', 'translate'),
        marketing: __('Discover a faster way to grow your business, starting today.', 'translate'),
        technical: __('Set the timeout in milliseconds, then restart the service to apply it.', 'translate'),
        legal: __('The customer shall notify the provider in writing within thirty days of any change.', 'translate'),
        medical: __('Take one tablet twice daily with food and consult your doctor if symptoms persist.', 'translate'),
    },
    tone: {
        neutral: __('Thank you for your message. We will look into it and get back to you.', 'translate'),
        friendly: __('Thanks for stopping by! We are happy to help with anything you need.', 'translate'),
        professional: __('Thank you for your enquiry. A member of our team will respond shortly.', 'translate'),
        casual: __('Hey, thanks for dropping in. Give us a shout if you need anything.', 'translate'),
        formal: __('We kindly request that you submit the completed form at your earliest convenience.', 'translate'),
        playful: __('You found the good stuff. Go on, treat yourself, we will not tell anyone.', 'translate'),
        luxury: __('Crafted with care and delivered with discretion, for those who expect more.', 'translate'),
    },
};

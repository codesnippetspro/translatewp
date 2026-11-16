import { __ } from '@wordpress/i18n';
import { TextareaControl } from '@wordpress/components';
import { Sparkles } from 'lucide-react';

import { StyleCard } from '@/apps/admin/components/Translations/Style/StyleCard';
import { OptionCards } from '@/apps/admin/components/Languages/Switcher/OptionCards';
import type { TranslationsStyle } from '@/apps/admin/stores/settingsStore';
import { getProState, isProActive } from '@/shared/lib/pro/proState';
import { ProMarker } from '@/shared/components/pro/ProMarker';
import { ProLock } from '@/shared/components/pro/ProLock';

const AUDIENCE_MAX_LENGTH = 200;

interface AdvancedStyleControlsProps
{
    style: TranslationsStyle;
    updateStyle: <K extends keyof TranslationsStyle>(key: K, value: TranslationsStyle[K]) => void;
    stacked?: boolean;
}

const AUDIENCE_DESCRIPTION = __('Who reads the translations. The vocabulary and the level of explanation follow it.', 'translate');
const LENGTH_DESCRIPTION = __('How long a translation may run next to its original.', 'translate');
const ADAPTATION_DESCRIPTION = __('What happens to idioms, examples and cultural references.', 'translate');

type CardProps = {
    title: string;
    description: string;
    feature: string;
    locked: boolean;
    activeBadge?: React.ReactNode;
    children: React.ReactNode;
};

const Card = ({ title, description, feature, locked, activeBadge, children }: CardProps) => (
    <StyleCard title={title} description={description} badge={locked ? undefined : activeBadge}>
        <div className="flex flex-1 flex-col">
            {locked ? <ProLock feature={feature} className="flex-1">{children}</ProLock> : children}
        </div>
    </StyleCard>
);

export const AdvancedStyleControls = ({ style, updateStyle, stacked = false }: AdvancedStyleControlsProps) =>
{
    const proActive = isProActive(getProState());

    const audience = (
        <div className="h-full" data-audience-field>
            <TextareaControl
                __nextHasNoMarginBottom
                label={__('Audience', 'translate')}
                hideLabelFromVision
                value={style.audience ?? ''}
                rows={4}
                maxLength={AUDIENCE_MAX_LENGTH}
                placeholder={__('For example: first-time buyers, hospital staff, developers', 'translate')}
                onChange={(value) => updateStyle('audience', value.slice(0, AUDIENCE_MAX_LENGTH))}
                disabled={!proActive}
                className="h-full [&_.components-base-control__field]:h-full [&_textarea]:box-border! [&_textarea]:h-full! [&_textarea]:min-h-24! [&_textarea]:resize-y [&_textarea]:border-translatewp-border! [&_textarea]:rounded-sm!"
            />
        </div>
    );

    const length = (
        <OptionCards
            name="length"
            label={__('Length', 'translate')}
            columns={1}
            value={style.length ?? 'match'}
            onChange={(value) => updateStyle('length', value as TranslationsStyle['length'])}
            options={[
                { value: 'match', label: __('Match the original', 'translate'), description: __('About as long as the original.', 'translate') },
                { value: 'shorter', label: __('Shorter', 'translate'), description: __('Filler dropped, every fact kept.', 'translate') },
                { value: 'longer', label: __('Longer', 'translate'), description: __('Room to explain where it helps.', 'translate') },
            ]}
        />
    );

    const adaptation = (
        <OptionCards
            name="cultural-adaptation"
            label={__('Cultural adaptation', 'translate')}
            columns={1}
            value={style.cultural_adaptation ?? 'keep'}
            onChange={(value) => updateStyle('cultural_adaptation', value as TranslationsStyle['cultural_adaptation'])}
            options={[
                { value: 'keep', label: __('Keep as written', 'translate'), description: __('Idioms, examples and references are translated as they are.', 'translate') },
                { value: 'adapt', label: __('Adapt', 'translate'), description: __('Idioms, examples and references land naturally for the reader; names and facts stay.', 'translate') },
            ]}
        />
    );

    return (
        <section className="flex flex-col gap-4" aria-label={__('Pro style controls', 'translate')}>
            {!proActive && <div className="flex flex-wrap items-center gap-2">
                <h3 className="m-0! text-sm! font-bold! text-translatewp-neutral!">{__('Pro style controls', 'translate')}</h3>
                <ProMarker />
            </div>}
        <div className={stacked ? 'grid grid-cols-1 items-stretch gap-6 xl:grid-cols-3' : 'grid grid-cols-1 items-start gap-6 lg:grid-cols-3'} data-advanced-style>
            <Card
                title={__('Audience', 'translate')}
                description={AUDIENCE_DESCRIPTION}
                feature={__('Audience', 'translate')}
                locked={!proActive}
                activeBadge={<Sparkles size={16} className="text-translatewp-primary" aria-hidden="true" />}
            >
                {audience}
            </Card>
            <Card title={__('Length', 'translate')} description={LENGTH_DESCRIPTION} feature={__('Length', 'translate')} locked={!proActive}>{length}</Card>
            <Card title={__('Cultural adaptation', 'translate')} description={ADAPTATION_DESCRIPTION} feature={__('Cultural adaptation', 'translate')} locked={!proActive}>{adaptation}</Card>
        </div>
        </section>
    );
};

import { Button, RadioControl, SelectControl, TextControl, ToggleControl, __experimentalNumberControl as NumberControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { useRef, useState } from '@wordpress/element';

import type { NetworkScopeData, NetworkScopeField, NetworkScopeGroup, ScopeState } from '@/apps/network-admin/types';
import { Card, Section } from '@/shared/components/adminSurface';
import { ColorPicker } from '@/shared/components/form/colorPicker';
import { Range } from '@/shared/components/form/range';
import { ConfirmDialog } from '@/shared/components/confirmDialog';
import { ProBadge } from '@/shared/components/pro/ProBadge';
import { ProLock } from '@/shared/components/pro/ProLock';
import { useConfirmDialog } from '@/shared/components/confirmDialog/useConfirmDialog';

interface FormAction
{
    url: string;
    action: string;
    nonce: string;
}

type Subsite = NetworkScopeData['subsites'][number];

const RANGE_LABELS: Record<string, [string, string]> = {
    'translations.style.formality': [__('Informal', 'translate'), __('Formal', 'translate')],
    'translations.style.creativity': [__('Precise (literal)', 'translate'), __('Creative (fluid)', 'translate')],
};

const stateOptions = () => [
    { value: 'locked', label: __('Locked', 'translate'), description: __('Every subsite uses the network value and cannot change it.', 'translate') },
    { value: 'default', label: __('Network default', 'translate'), description: __('Subsites start from the network value and may change it.', 'translate') },
    { value: 'local', label: __('Set by each subsite', 'translate'), description: __('Each subsite sets its own value.', 'translate') },
];

const TEXT_FIELD_MAX_LENGTH = 200;

const exceptionLabel = (state: ScopeState): string =>
{
    if (state === 'locked') return __('locked', 'translate');
    if (state === 'local') return __('set by the subsite', 'translate');
    return __('network default', 'translate');
};

const encode = (value: unknown): string =>
{
    if (typeof value === 'boolean') return value ? '1' : '0';
    return value === null || value === undefined ? '' : String(value);
};

const Hidden = ({ form, op, group }: { form: FormAction; op: string; group: string }) => (
    <>
        <input type="hidden" name="action" value={form.action} />
        <input type="hidden" name="_wpnonce" value={form.nonce} />
        <input type="hidden" name="op" value={op} />
        <input type="hidden" name="group" value={group} />
    </>
);

const SubsiteChecklist = ({ subsites, selected, onChange, dataKey }: { subsites: Subsite[]; selected: number[]; onChange: (next: number[]) => void; dataKey: string }) => (
    <div className="flex flex-wrap gap-x-4 gap-y-2">
        {subsites.map(site => (
            <label key={site.id} className="flex items-center gap-2 text-sm text-translatewp-neutral">
                <input
                    type="checkbox"
                    {...{ [`data-${dataKey}-site`]: String(site.id) }}
                    checked={selected.includes(site.id)}
                    onChange={() => onChange(selected.includes(site.id) ? selected.filter(id => id !== site.id) : [...selected, site.id])}
                />
                {site.name}
            </label>
        ))}
    </div>
);

const FieldControl = ({ field, value, onChange, proActive = false }: { field: NetworkScopeField; value: unknown; onChange: (value: unknown) => void; proActive?: boolean }) =>
{
    switch (field.type) {
        case 'boolean':
            return <ToggleControl __nextHasNoMarginBottom label={field.label} checked={value === true} onChange={(checked) => onChange(checked)} />;
        case 'select':
            return (
                <SelectControl
                    __next40pxDefaultSize
                    __nextHasNoMarginBottom
                    label={field.label}
                    value={encode(value)}
                    options={Object.entries(field.options).map(([optionValue, label]) =>
                    {
                        const locked = !proActive && (field.pro_values ?? []).includes(optionValue);
                        /* translators: %s: a choice only TranslateWP Pro offers, such as "Side tab". */
                        return { value: optionValue, label: locked ? sprintf(__('%s (Pro)', 'translate'), String(label)) : String(label), disabled: locked };
                    })}
                    onChange={(next) => onChange(next)}
                    className="w-[28rem] max-w-full"
                />
            );
        case 'color':
            return <ColorPicker label={field.label} value={encode(value)} onChange={(next) => onChange(next)} />;
        case 'roles':
        case 'role_map': {
            const selected = Array.isArray(value) ? (value as string[]) : [];
            return (
                <div className="flex flex-col gap-2" data-roles-field={field.key}>
                    <span className="text-[11px] font-medium uppercase tracking-wide text-translatewp-neutral">{field.label}</span>
                    <p className="m-0! text-sm! text-translatewp-muted!">{__('No role ticked shows translations to everyone.', 'translate')}</p>
                    <div className="flex flex-wrap gap-x-4 gap-y-2">
                        {Object.entries(field.options).map(([slug, label]) => (
                            <label key={slug} className="flex items-center gap-2 text-sm text-translatewp-neutral">
                                <input
                                    type="checkbox"
                                    checked={selected.includes(slug)}
                                    onChange={() => onChange(selected.includes(slug) ? selected.filter(item => item !== slug) : [...selected, slug])}
                                />
                                {String(label)}
                            </label>
                        ))}
                    </div>
                </div>
            );
        }
        case 'text':
            return (
                <div className="flex w-full max-w-xl flex-col gap-2" data-text-field={field.key}>
                    <span className="text-[11px] font-medium uppercase tracking-wide text-translatewp-neutral">{field.label}</span>
                    {field.help && <p className="m-0! text-sm! text-translatewp-muted!">{field.help}</p>}
                    <TextControl
                        __next40pxDefaultSize
                        __nextHasNoMarginBottom
                        label={field.label}
                        hideLabelFromVision
                        value={encode(value)}
                        placeholder={field.placeholder}
                        maxLength={TEXT_FIELD_MAX_LENGTH}
                        onChange={(next) => onChange(next.slice(0, TEXT_FIELD_MAX_LENGTH))}
                        className="[&_input]:border-translatewp-border! [&_input]:rounded-sm!"
                    />
                </div>
            );
        case 'number':
            return (
                <NumberControl
                    __next40pxDefaultSize
                    label={field.label}
                    value={Number(value)}
                    min={0}
                    max={500}
                    onChange={(next) => onChange(next === undefined || next === '' ? value : Math.max(0, Math.trunc(Number(next))))}
                    className="max-w-40"
                />
            );
        case 'range':
            return (
                <div className="flex flex-col gap-2">
                    <span className="text-sm font-semibold text-translatewp-neutral">{field.label}</span>
                    <Range
                        label={field.label}
                        beginningLabel={RANGE_LABELS[field.key]?.[0] ?? ''}
                        endingLabel={RANGE_LABELS[field.key]?.[1] ?? ''}
                        min={0}
                        max={10}
                        step={1}
                        value={Number(value)}
                        onChange={(next) => onChange(next)}
                    />
                </div>
            );
    }

    return null;
};

const StateForm = ({ group, form }: { group: NetworkScopeGroup; form: FormAction }) =>
{
    const formRef = useRef<HTMLFormElement>(null);
    const [state, setState] = useState<ScopeState>(group.state);

    return (
        <form ref={formRef} method="post" action={form.url} data-scope-form="state" className="flex flex-col gap-3">
            <Hidden form={form} op="state" group={group.id} />
            <RadioControl
                label={__('Who sets these settings', 'translate')}
                selected={state}
                options={stateOptions()}
                onChange={(value) => setState(value as ScopeState)}
                name="state"
            />
            <div>
                <Button variant="secondary" type="button" onClick={() => formRef.current?.requestSubmit()} disabled={state === group.state}>
                    {__('Save scope', 'translate')}
                </Button>
            </div>
        </form>
    );
};

const ValueForm = ({ group, form, subsites, requestConfirmation, proActive = false }: {
    proActive?: boolean;
    group: NetworkScopeGroup;
    form: FormAction;
    subsites: Subsite[];
    requestConfirmation: ReturnType<typeof useConfirmDialog>['requestConfirmation'];
}) =>
{
    const formRef = useRef<HTMLFormElement>(null);
    const [values, setValues] = useState<Record<string, unknown>>(() => Object.fromEntries(group.fields.map(field => [field.key, field.value])));
    const [applyTo, setApplyTo] = useState<'all' | 'chosen'>('all');
    const [sites, setSites] = useState<number[]>([]);

    const save = async () =>
    {
        if (applyTo === 'all') {
            const confirmed = await requestConfirmation({
                title: sprintf(__('Apply %s to every subsite?', 'translate'), group.label),
                message: __('Every subsite takes this value, replacing its own.', 'translate'),
                confirmLabel: __('Apply to every subsite', 'translate'),
            });
            if (!confirmed) return;
        }
        formRef.current?.requestSubmit();
    };

    return (
        <form ref={formRef} method="post" action={form.url} data-scope-form="value" className="flex flex-col gap-4 border-t border-translatewp-border pt-4">
            <Hidden form={form} op="value" group={group.id} />
            {group.fields.map(field => Array.isArray(values[field.key])
                ? ((values[field.key] as string[]).length
                    ? (values[field.key] as string[]).map(item => <input key={`${field.key}-${item}`} type="hidden" name={`values[${field.key}][]`} value={item} />)
                    : <input key={field.key} type="hidden" name={`values[${field.key}]`} value="" />)
                : <input key={field.key} type="hidden" name={`values[${field.key}]`} value={encode(values[field.key])} />)}
            {applyTo === 'chosen' && sites.map(id => <input key={id} type="hidden" name="sites[]" value={String(id)} />)}
            <span className="text-sm font-bold text-translatewp-neutral">{__('Network value', 'translate')}</span>
            <div className="flex flex-col gap-4">
                {group.fields.map(field => field.pro && !proActive ? (
                    <div key={field.key} className="flex w-full max-w-xl items-end gap-3" data-pro-field={field.key}>
                        <ProLock feature={field.label} className="min-w-0 w-fit max-w-full">
                            <FieldControl field={field} value={values[field.key]} onChange={() => undefined} />
                        </ProLock>
                        <ProBadge />
                    </div>
                ) : (
                    <FieldControl key={field.key} field={field} value={values[field.key]} proActive={proActive} onChange={(next) => setValues(previous => ({ ...previous, [field.key]: next }))} />
                ))}
            </div>
            <RadioControl
                label={__('Apply to', 'translate')}
                selected={applyTo}
                options={[
                    { value: 'all', label: __('Every subsite', 'translate') },
                    { value: 'chosen', label: __('Chosen subsites', 'translate') },
                ]}
                onChange={(value) => setApplyTo(value === 'chosen' ? 'chosen' : 'all')}
                name="apply_to"
            />
            {applyTo === 'chosen' && <SubsiteChecklist subsites={subsites} selected={sites} onChange={setSites} dataKey="value" />}
            <div>
                <Button variant="primary" type="button" onClick={() => void save()} disabled={applyTo === 'chosen' && sites.length === 0}>
                    {__('Save value', 'translate')}
                </Button>
            </div>
        </form>
    );
};

const ExceptionsForm = ({ group, form, subsites, requestConfirmation }: {
    group: NetworkScopeGroup;
    form: FormAction;
    subsites: Subsite[];
    requestConfirmation: ReturnType<typeof useConfirmDialog>['requestConfirmation'];
}) =>
{
    const formRef = useRef<HTMLFormElement>(null);
    const clearRef = useRef<HTMLFormElement>(null);
    const exceptionRef = useRef<HTMLInputElement>(null);
    const [sites, setSites] = useState<number[]>([]);
    const names = new Map(subsites.map(site => [String(site.id), site.name]));
    const current = Object.entries(group.exceptions);

    const submit = (exception: string) =>
    {
        if (exceptionRef.current) exceptionRef.current.value = exception;
        formRef.current?.requestSubmit();
    };

    const clearAll = async () =>
    {
        const confirmed = await requestConfirmation({
            /* translators: %s: settings group name */
            title: sprintf(__('Clear every exception for %s?', 'translate'), group.label),
            message: __('Every subsite goes back to the choice above.', 'translate'),
            confirmLabel: __('Clear exceptions', 'translate'),
        });
        if (confirmed) clearRef.current?.requestSubmit();
    };

    return (
        <>
        <form ref={formRef} method="post" action={form.url} data-scope-form="exceptions" className="flex flex-col gap-3 border-t border-translatewp-border pt-4">
            <Hidden form={form} op="exceptions" group={group.id} />
            <input ref={exceptionRef} type="hidden" name="exception" value="" />
            {sites.map(id => <input key={id} type="hidden" name="sites[]" value={String(id)} />)}
            <span className="text-sm font-bold text-translatewp-neutral">{__('Exceptions for chosen subsites', 'translate')}</span>
            {current.length > 0 ? (
                <ul className="m-0 list-disc ps-5 text-sm text-translatewp-neutral!">
                    {current.map(([siteId, state]) => <li key={siteId}>{`${names.get(siteId) ?? `#${siteId}`}: ${exceptionLabel(state)}`}</li>)}
                </ul>
            ) : (
                <p className="m-0 text-sm text-translatewp-muted!">{__('No exceptions. Every subsite follows the choice above.', 'translate')}</p>
            )}
            <SubsiteChecklist subsites={subsites} selected={sites} onChange={setSites} dataKey="exception" />
            <div className="flex flex-wrap gap-2">
                <Button variant="secondary" type="button" disabled={sites.length === 0} onClick={() => submit('local')}>{__('Unlock for chosen subsites', 'translate')}</Button>
                <Button variant="secondary" type="button" disabled={sites.length === 0} onClick={() => submit('locked')}>{__('Lock for chosen subsites', 'translate')}</Button>
                <Button variant="tertiary" type="button" disabled={current.length === 0} onClick={() => void clearAll()}>{__('Clear exceptions', 'translate')}</Button>
            </div>
        </form>
        <form ref={clearRef} method="post" action={form.url} hidden>
            <Hidden form={form} op="exceptions" group={group.id} />
            <input type="hidden" name="exception" value="clear" />
            {current.map(([siteId]) => <input key={siteId} type="hidden" name="sites[]" value={siteId} />)}
        </form>
        </>
    );
};

export const SettingsScopeSection = ({ scope, form, account = null, proActive = false }: { scope: NetworkScopeData; form: FormAction; account?: React.ReactNode; proActive?: boolean }) =>
{
    const { requestConfirmation, confirmDialogProps } = useConfirmDialog();

    return (
        <Section
            title={__('Settings scope', 'translate')}
            description={__('Choose who sets each group of settings, set the network value, and make exceptions for chosen subsites.', 'translate')}
        >
            {scope.status === 'scope-saved' && (
                <p className="m-0 text-sm text-translatewp-neutral!" role="status">{__('Settings scope saved.', 'translate')}</p>
            )}
            {(scope.status === 'failed' || scope.status === 'denied') && (
                <p className="m-0 text-sm text-translatewp-error" role="alert">{__('That change could not be saved. Check the values and try again.', 'translate')}</p>
            )}
            {scope.status === 'position-applied' && (
                <p className="m-0 text-sm text-translatewp-neutral!" role="status">{__('The switcher position now applies to every subsite.', 'translate')}</p>
            )}
            {scope.status === 'no-position' && (
                <p className="m-0 text-sm text-translatewp-error" role="alert">{__('Drag the switcher on the main site first; there is no position to apply yet.', 'translate')}</p>
            )}
            {scope.status === 'pro-required' && (
                <p className="m-0 text-sm text-translatewp-error" role="alert">{__('Applying the switcher position to every subsite needs TranslateWP Pro.', 'translate')}</p>
            )}
            <div className="lg:columns-2 lg:gap-6" data-scope-groups>
            {scope.groups.map(group => (
                <div key={group.id} data-scope-group={group.id} className="mb-6 break-inside-avoid last:mb-0">
                    <Card title={group.label}>
                        {group.id === 'account' ? account : (
                            <>
                                {group.switchable
                                    ? <StateForm group={group} form={form} />
                                    : <p className="m-0 text-sm text-translatewp-muted!">{__('Locked for every subsite.', 'translate')}</p>}
                                {group.fields.length > 0 && <ValueForm group={group} form={form} subsites={scope.subsites} requestConfirmation={requestConfirmation} proActive={proActive} />}
                                {group.switchable && <ExceptionsForm group={group} form={form} subsites={scope.subsites} requestConfirmation={requestConfirmation} />}
                            </>
                        )}
                    </Card>
                </div>
            ))}
                <div className="mb-6 break-inside-avoid last:mb-0" data-scope-group="switcher_position">
                    <Card title={__('Switcher position', 'translate')} badge={<ProBadge />}>
                        <p className="m-0 text-sm text-translatewp-muted!">
                            {__('Every subsite takes the floating switcher position you dragged into place on the main site.', 'translate')}
                        </p>
                        {proActive ? (
                            <form method="post" action={form.url} data-scope-form="switcher_position">
                                <input type="hidden" name="action" value={form.action} />
                                <input type="hidden" name="_wpnonce" value={form.nonce} />
                                <input type="hidden" name="op" value="switcher_position" />
                                <Button variant="primary" type="submit">{__('Apply the main site position', 'translate')}</Button>
                            </form>
                        ) : (
                            <ProLock feature={__('Switcher position for every subsite', 'translate')} className="w-fit">
                                <Button variant="primary" disabled>{__('Apply the main site position', 'translate')}</Button>
                            </ProLock>
                        )}
                    </Card>
                </div>
            </div>
            <ConfirmDialog {...confirmDialogProps} />
        </Section>
    );
};

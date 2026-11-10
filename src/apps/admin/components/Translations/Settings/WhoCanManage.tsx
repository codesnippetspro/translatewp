import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { CheckboxControl } from '@wordpress/components';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { getTranslationManagers, saveRolePermissions, saveTranslationManagers, type RolePermissions, type TranslationManager, type TranslationManagersCard } from '@/shared/api/admin';
import { ManagedByNetworkNote } from '@/shared/components/managedByNetwork';
import { Table, TableCell, TableHeaderRow, TableRow } from '@/shared/components/form/table';
import { canAdminister } from '@/shared/lib/access';

const QUERY_KEY = ['translationManagers'];

const CAPABILITIES = [
    { key: 'edit_translations', label: __('Edit translations', 'translate') },
    { key: 'retranslate', label: __('Retranslate', 'translate') },
    { key: 'manage_settings', label: __('Manage settings', 'translate') },
    { key: 'manage_lexicon', label: __('Manage the Lexicon', 'translate') },
] as const;

type CapabilityKey = typeof CAPABILITIES[number]['key'];

/** Translations > Settings, for administrators: what each role may do, and the users named besides. */
export const WhoCanManage = () =>
{
    const administrator = canAdminister();
    const queryClient = useQueryClient();
    const [failed, setFailed] = useState(false);
    const { data, isError } = useQuery({ queryKey: QUERY_KEY, queryFn: getTranslationManagers, enabled: administrator });
    const onSuccess = (result: TranslationManagersCard) => queryClient.setQueryData(QUERY_KEY, result);
    const saveRoles = useMutation({ mutationFn: (roles: RolePermissions) => saveRolePermissions(roles), onMutate: () => setFailed(false), onSuccess, onError: () => setFailed(true) });
    const saveUsers = useMutation({ mutationFn: (granted: number[]) => saveTranslationManagers(granted), onMutate: () => setFailed(false), onSuccess, onError: () => setFailed(true) });

    if (!administrator) return null;

    const roleList = data?.roleList ?? [];
    const users: TranslationManager[] = data?.users ?? [];
    const editable = data?.editable !== false;
    const busy = saveRoles.isPending || saveUsers.isPending;
    const holds = (key: CapabilityKey, role: string): boolean => data?.roles?.[key]?.[role] === true;

    const toggle = (key: CapabilityKey, role: string, allowed: boolean) =>
    {
        const map: RolePermissions = {};
        for (const capability of CAPABILITIES)
        {
            map[capability.key] = Object.fromEntries(roleList.map(each =>
                [each.slug, capability.key === key && each.slug === role ? allowed : holds(capability.key, each.slug)]));
        }
        saveRoles.mutate(map);
    };

    return (
        <div className="rounded-lg border border-translatewp-border p-5 flex flex-col gap-5" data-who-can-manage>
            <h3 className="text-sm! font-bold! text-translatewp-neutral! m-0!">{__('Who can manage translations', 'translate')}</h3>
            {!editable && <ManagedByNetworkNote />}
            {isError && <p className="text-sm text-translatewp-error m-0" role="alert">{__('The roles could not be loaded. Reload the page to try again.', 'translate')}</p>}
            {data && (
                <Table
                    containerProps={{
                        className: 'focus-visible:outline-2! focus-visible:outline-solid! focus-visible:outline-offset-2! focus-visible:outline-translatewp-primary!',
                        tabIndex: 0,
                        role: 'region',
                        'aria-label': __('Who can manage translations', 'translate'),
                    }}
                    header={(
                        <TableHeaderRow>
                            <TableCell variant="header">{__('Role', 'translate')}</TableCell>
                            {CAPABILITIES.map(capability => (
                                <TableCell key={capability.key} variant="header" align="center">{capability.label}</TableCell>
                            ))}
                        </TableHeaderRow>
                    )}
                >
                    <TableRow data-role-row="administrator">
                        <TableCell variant="row-header">{data.administratorName ?? __('Administrator', 'translate')}</TableCell>
                        {CAPABILITIES.map(capability => (
                            <TableCell key={capability.key} align="center">
                                <input className="m-0! align-middle" type="checkbox" checked disabled readOnly aria-label={`${data.administratorName ?? __('Administrator', 'translate')}: ${capability.label}`} />
                            </TableCell>
                        ))}
                    </TableRow>
                    {roleList.map(role => (
                        <TableRow key={role.slug} data-role-row={role.slug}>
                            <TableCell variant="row-header">{role.name}</TableCell>
                            {CAPABILITIES.map(capability => (
                                <TableCell key={capability.key} align="center">
                                    <input
                                        className="m-0! align-middle"
                                        type="checkbox"
                                        checked={holds(capability.key, role.slug)}
                                        disabled={!editable || busy}
                                        aria-label={`${role.name}: ${capability.label}`}
                                        onChange={event => toggle(capability.key, role.slug, event.target.checked)}
                                    />
                                </TableCell>
                            ))}
                        </TableRow>
                    ))}
                </Table>
            )}
            <p className="text-sm text-translatewp-muted! m-0">
                {__('Edit translations opens edit mode, the editor and Add to Lexicon. Retranslate uses quota. Manage settings covers the translation and switcher settings. The connection, languages, your own AI key, billing and the Advanced page stay with administrators. Everyone sees the translated site.', 'translate')}
            </p>
            {users.length > 0 && (
                <div className="flex flex-col gap-2">
                    <span className="text-sm font-bold text-translatewp-neutral!">{__('Also these users', 'translate')}</span>
                    <p className="text-sm text-translatewp-muted! m-0">{__('Whatever their role, they can manage the settings and the Lexicon.', 'translate')}</p>
                    <ul className="flex flex-col gap-2 m-0 p-0 list-none">
                        {users.map(user => (
                            <li key={user.id} className="m-0">
                                <CheckboxControl
                                    __nextHasNoMarginBottom
                                    label={`${user.name} (${user.email})`}
                                    checked={user.granted}
                                    disabled={busy}
                                    onChange={checked => saveUsers.mutate(users.filter(each => each.id === user.id ? checked : each.granted).map(each => each.id))}
                                />
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {failed && <p className="text-sm text-translatewp-error m-0" role="alert">{__('The change could not be saved. Try again.', 'translate')}</p>}
        </div>
    );
};

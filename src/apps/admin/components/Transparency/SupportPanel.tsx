import { useState } from '@wordpress/element';
import { Button, Modal, Spinner, TextControl, TextareaControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Check, Copy } from 'lucide-react';

import { Card } from '@/shared/components/adminSurface';
import { ProMarker } from '@/shared/components/pro/ProMarker';
import { ProUnlockButton } from '@/shared/components/pro/ProUnlockButton';
import { getProState, isProActive } from '@/shared/lib/pro/proState';
import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';
import { getDebugInfoQueryOptions } from '@/shared/query-options/admin';
import { openSupportTicketMutationOptions } from '@/shared/mutation-options/admin';
import { FeedbackForm } from '@/apps/admin/components/Transparency/FeedbackForm';

export const SUPPORT_ADDRESS = 'team@translatewp.ai';

export const mailtoFallback = (name: string, message: string, subject: string, debug: string, replyTo = ''): string =>
{
    const body = `${message}\n\n${name}${replyTo !== '' ? `\nReply to: ${replyTo}` : ''}\n\n\n${debug}`;

    return `mailto:${SUPPORT_ADDRESS}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
};

const DebugReport = ({ text }: { text: string }) =>
{
    const [copied, setCopied] = useState(false);

    const copy = async () =>
    {
        try
        {
            await navigator.clipboard.writeText(text);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        }
        catch
        {
            setCopied(false);
        }
    };

    return (
        <div className="flex flex-col gap-3">
            <textarea
                readOnly
                value={text}
                rows={14}
                onClick={event => event.currentTarget.select()}
                onFocus={event => event.currentTarget.select()}
                className="block! w-full! resize-y rounded-sm! border! border-translatewp-border! bg-translatewp-selection! p-4! font-mono! text-xs! leading-5! text-translatewp-neutral! shadow-none! max-h-[480px] min-h-40"
                spellCheck={false}
                aria-label={__('Support report', 'translate')}
                data-debug-report
            />
            <Button variant="secondary" className="rounded-sm! gap-2 w-fit" onClick={() => void copy()} data-copy-debug>
                {copied ? <Check size={14} aria-hidden="true" /> : <Copy size={14} aria-hidden="true" />}
                {copied ? __('Copied', 'translate') : __('Copy support report', 'translate')}
            </Button>
        </div>
    );
};

const TicketModal = ({ debug, onClose }: { debug: string; onClose: () => void }) =>
{
    const [name, setName] = useState('');
    const [message, setMessage] = useState('');
    const [email, setEmail] = useState(window.TranslateWP?.context?.user_email ?? '');
    const [done, setDone] = useState<'sent' | 'mail' | null>(null);
    const [refused, setRefused] = useState(false);
    const mutation = useMutation(openSupportTicketMutationOptions());
    // Empty means the account's address; anything else has to look like an email.
    const replyTo = email.trim();
    const emailInvalid = refused || (replyTo !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(replyTo));

    const fallback = (subject: string) =>
    {
        window.open(mailtoFallback(name, message, subject, debug, replyTo), '_blank', 'noopener');
        setDone('mail');
    };

    const send = async () =>
    {
        const subject = `[TranslateWP] Support request from ${window.location.hostname}`;
        try
        {
            const result = await mutation.mutateAsync({ name, message, email: replyTo });
            if (result.sent) setDone('sent');
            else fallback(result.subject || subject);
        }
        catch (error)
        {
            // The address was refused: the form says so; only a failed delivery opens a mail link.
            if ((error as { code?: string })?.code === 'invalid_reply_address') setRefused(true);
            else fallback(subject);
        }
    };

    return (
        <Modal title={__('Open a support ticket', 'translate')} onRequestClose={onClose} className="w-[520px]! max-w-[92vw]! rounded-xl!">
            {done === 'sent' && (
                <p className="m-0 text-sm text-translatewp-neutral" role="status">{
                    /* translators: %s: the email address the answer goes to. */
                    sprintf(__('Sent. We answer by email, to %s.', 'translate'), email.trim() || __('the address of your WordPress account', 'translate'))
                }</p>
            )}
            {done === 'mail' && (
                <p className="m-0 text-sm text-translatewp-neutral" role="status">
                    {__('This site could not send email, so your mail app opened with the request ready to send to', 'translate')} {SUPPORT_ADDRESS}.
                </p>
            )}
            {done === null && (
                <div className="flex flex-col gap-4">
                    <TextControl label={__('Name', 'translate')} value={name} onChange={setName} __nextHasNoMarginBottom __next40pxDefaultSize />
                    <TextControl
                        type="email"
                        label={__('Reply to', 'translate')}
                        help={emailInvalid
                            ? <span className="text-translatewp-error!" data-reply-error>{__('Enter an email address we can answer.', 'translate')}</span>
                            : __('We answer here. Change it if you want the reply somewhere else.', 'translate')}
                        aria-invalid={emailInvalid || undefined}
                        value={email}
                        onChange={(value) => { setEmail(value); setRefused(false); }}
                        __nextHasNoMarginBottom
                        __next40pxDefaultSize
                    />
                    <TextareaControl label={__('How can we help you?', 'translate')} value={message} onChange={setMessage} rows={6} __nextHasNoMarginBottom />
                    <p className="m-0 text-xs text-translatewp-muted">{__('The support report below is attached to your message.', 'translate')}</p>
                    <div className="flex justify-end gap-2">
                        <Button variant="tertiary" onClick={onClose}>{__('Cancel', 'translate')}</Button>
                        <Button variant="primary" className="rounded-sm!" disabled={name.trim() === '' || message.trim() === '' || emailInvalid || mutation.isPending} isBusy={mutation.isPending} onClick={() => void send()} data-send-ticket>
                            {__('Send', 'translate')}
                        </Button>
                    </div>
                </div>
            )}
        </Modal>
    );
};

export const SupportPanel = () =>
{
    const { data, isPending, isError, refetch } = useQuery(getDebugInfoQueryOptions());
    const { data: connectStatus } = useQuery(getConnectStatusQueryOptions());
    const proActive = isProActive(getProState(connectStatus));
    const [ticketOpen, setTicketOpen] = useState(false);
    const text = data?.text ?? '';

    // A Pro site leads with its ticket line; a Free site sees the locked ticket after the feedback form.
    const ticketCard = (
        <Card title={__('Need a hand?', 'translate')} badge={proActive ? undefined : <ProMarker />}>
                <p className="m-0 text-sm text-translatewp-muted">
                    {__('Tell us what happened and we answer by email. The support report goes with your message.', 'translate')}
                </p>
                {proActive ? (
                    <Button variant="primary" className="rounded-sm! w-fit" onClick={() => setTicketOpen(true)} data-open-ticket>
                        {__('Open a ticket', 'translate')}
                    </Button>
                ) : (
                    <ProUnlockButton feature={__('support tickets', 'translate')} />
                )}
        </Card>
    );

    return (
        <div className="flex flex-col gap-8">
            <div className="grid grid-cols-1 gap-8 lg:grid-cols-2" data-support-row>
                {proActive && ticketCard}
                <FeedbackForm />
                {!proActive && ticketCard}
            </div>
            <Card title={__('Support report', 'translate')}>
                <p className="m-0 text-sm text-translatewp-muted">
                    {__('What the team needs to look into a problem: versions, settings, the environment and the latest plugin events. No visitor data is included. Click the box to select everything.', 'translate')}
                </p>
                {isPending && <Spinner />}
                {isError && (
                    <div className="flex flex-wrap items-center gap-3" role="alert">
                        <p className="m-0 text-sm text-translatewp-muted">{__('The report could not be built.', 'translate')}</p>
                        <Button variant="secondary" className="rounded-sm!" onClick={() => { void refetch(); }}>{__('Try again', 'translate')}</Button>
                    </div>
                )}
                {text !== '' && <DebugReport text={text} />}
            </Card>
            {ticketOpen && <TicketModal debug={text} onClose={() => setTicketOpen(false)} />}
        </div>
    );
};

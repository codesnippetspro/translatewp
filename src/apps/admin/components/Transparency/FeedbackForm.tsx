import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, TextareaControl } from '@wordpress/components';
import { useMutation } from '@tanstack/react-query';

import { submitPluginFeedbackMutationOptions } from '@/shared/mutation-options/admin';

type FeedbackStatus = 'idle' | 'success' | 'error';

export const FeedbackForm = () =>
{
    const [message, setMessage] = useState('');
    const [status, setStatus] = useState<FeedbackStatus>('idle');
    const [statusMessage, setStatusMessage] = useState('');

    const feedbackMutation = useMutation({
        ...submitPluginFeedbackMutationOptions(),
        onSuccess: () =>
        {
            setMessage('');
            setStatus('success');
            setStatusMessage('Thanks, your feedback was sent.');
        },
        onError: () =>
        {
            setStatus('error');
            setStatusMessage('Feedback could not be sent. Try again in a moment.');
        },
    });

    const handleSubmit = (event: { preventDefault: () => void }) =>
    {
        event.preventDefault();
        const trimmed = message.trim();
        if (!trimmed || feedbackMutation.isPending) {
            return;
        }

        setStatus('idle');
        setStatusMessage('');
        feedbackMutation.mutate(trimmed);
    };

    return (
        <section
            className="rounded-lg border border-translatewp-border bg-white p-6"
            aria-labelledby="feedback-heading"
        >
            <div className="mb-5">
                <h2 id="feedback-heading" className="m-0 text-lg font-semibold text-translatewp-neutral">
                    Help shape TranslateWP
                </h2>
                <p className="mb-0 mt-1 text-sm leading-6 text-translatewp-muted">
                    Share what is working, what is unclear, or what you need next.
                </p>
            </div>

            <form className="flex w-full flex-col gap-3" onSubmit={handleSubmit}>
                <div className="flex flex-col gap-2">
                    <span id="feedback-message-label" className="text-sm font-bold text-translatewp-neutral">
                        {__('Tell us what would make TranslateWP better', 'translate')}
                    </span>
                    <TextareaControl
                        __nextHasNoMarginBottom
                        name="message"
                        aria-labelledby="feedback-message-label"
                        required
                        maxLength={5000}
                        rows={5}
                        value={message}
                        onChange={(value) => setMessage(value)}
                        className="[&_textarea]:border-translatewp-border! [&_textarea]:rounded-sm! [&_textarea]:resize-none! w-full"
                    />
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <Button
                        variant="primary"
                        className="rounded-sm! text-sm!"
                        type="submit"
                        disabled={feedbackMutation.isPending || !message.trim()}
                        isBusy={feedbackMutation.isPending}
                    >
                        {feedbackMutation.isPending ? 'Sending…' : 'Send feedback'}
                    </Button>

                    {status !== 'idle' && (
                        <p
                            role={status === 'error' ? 'alert' : 'status'}
                            className="m-0 text-sm text-translatewp-muted"
                        >
                            {statusMessage}
                        </p>
                    )}
                </div>
            </form>
        </section>
    );
};

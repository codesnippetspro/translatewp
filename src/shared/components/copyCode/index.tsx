import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Check, Copy } from 'lucide-react';

// A snippet the user pastes somewhere: click anywhere on it to copy.
export const CopyCode = ({ value }: { value: string }) =>
{
    const [copied, setCopied] = useState(false);

    const copy = async () =>
    {
        try
        {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1500);
        }
        catch
        {
            setCopied(false);
        }
    };

    return (
        <button
            type="button"
            onClick={() => void copy()}
            title={copied ? __('Copied', 'translate') : __('Copy', 'translate')}
            aria-label={copied ? __('Copied', 'translate') : __('Copy', 'translate')}
            className="inline-flex cursor-pointer items-center gap-1.5 rounded-sm border border-translatewp-border bg-translatewp-selection px-1.5 py-0.5 align-baseline font-mono text-xs text-translatewp-neutral hover:border-translatewp-primary"
            data-copy-code={value}
        >
            <code className="bg-transparent p-0 text-xs">{value}</code>
            {copied ? <Check size={12} className="text-translatewp-success" aria-hidden="true" /> : <Copy size={12} className="text-translatewp-icons" aria-hidden="true" />}
        </button>
    );
};

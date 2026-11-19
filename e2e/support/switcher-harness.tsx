import { createRoot, useState } from '@wordpress/element';
import { SwitcherView } from '@/apps/translatewp/SwitcherView';

const languages = ['en', 'de', 'it', 'fr', 'es', 'nl', 'pt', 'lv', 'he', 'el']
    .map((locale, index) => ({ locale, slug: locale, active: true, default: index === 0 }));

const Placed = ({ trigger }: { trigger: 'flag' | 'name_flag' }) =>
{
    const [open, setOpen] = useState(false);
    return <SwitcherView languages={languages as never} variant="inline" switcher={{ type: 'dropdown', trigger } as never}
        selectedLocale="en" isOpen={open} notice={null} onOpenChange={setOpen} onLanguageSelect={() => undefined} onDismissNotice={() => undefined} />;
};

(window as unknown as { mountSwitcher: (host: HTMLElement, trigger: 'flag' | 'name_flag') => void }).mountSwitcher =
    (host, trigger) => createRoot(host).render(<Placed trigger={trigger} />);

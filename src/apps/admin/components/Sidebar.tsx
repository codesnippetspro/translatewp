import { Button, Tooltip } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, isRTL, sprintf } from '@wordpress/i18n';
import {
	BookOpen, Cable, ChevronDown, Globe, Languages, LayoutDashboard, LifeBuoy, ListChecks,
	PanelLeftClose, PanelLeftOpen, Search, Settings, SlidersHorizontal, Sparkles,
} from 'lucide-react';

import { DASHBOARD_PAGES } from '@/apps/admin/components/Dashboard/DashboardPage';
import { settingsPagesFor } from '@/apps/admin/components/SettingsPage';
import { buildAdminUrl, useNavigate } from '@/apps/admin/hooks/useNavigate';
import { usePageHash } from '@/apps/admin/hooks/usePageHash';
import codeSnippetsLogo from '@/shared/assets/code-snippets-logo.svg';
import { Icon as TranslateWPIcon } from '@/shared/components/Icon';
import { ProBadge } from '@/shared/components/pro/ProBadge';
import { canAdminister, managerCan } from '@/shared/lib/access';

const STORAGE_KEY = 'translatewp-sidebar-collapsed';
const MOBILE_QUERY = '(max-width: 782px)';
const ICON_SIZE = 18;
const CONTROL_CLASS = 'flex! items-center gap-[9px]! box-border w-full min-h-10 p-2.5! '
	+ 'rounded-md! text-[13px]! leading-5! no-underline!';
const LINK_CLASS = `${CONTROL_CLASS} hover:bg-translatewp-selection! `
	+ 'hover:text-translatewp-primary! focus-visible:bg-translatewp-selection! '
	+ 'focus-visible:text-translatewp-primary! focus-visible:outline-2 '
	+ 'focus-visible:outline-translatewp-primary focus-visible:-outline-offset-2 '
	+ 'focus-visible:shadow-none! aria-[current=page]:text-translatewp-primary! '
	+ 'aria-[current=location]:bg-[#edf2ff]! aria-[current=location]:text-translatewp-primary! '
	+ 'aria-[current=page]:only:bg-[#edf2ff]!';
const PAGE_ICONS = {
	overview: LayoutDashboard,
	indexing: ListChecks,
	support: LifeBuoy,
	languages: Languages,
	translation: Globe,
	switcher: SlidersHorizontal,
	connection: Cable,
	advanced: Settings,
	'ai-model': Sparkles,
};

export const Sidebar = () =>
{
	const administers = canAdminister();
	const can = managerCan();
	const { route } = useNavigate();
	const settingsPages = settingsPagesFor(administers);
	const pages = route === '/settings' ? settingsPages : DASHBOARD_PAGES;
	const { activePageName } = usePageHash(pages.map(page => page.name), pages[0].name);
	const [collapsed, setCollapsed] = useState(() => {
		try {
			return window.localStorage.getItem(STORAGE_KEY) === 'true';
		} catch {
			return false;
		}
	});
	const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
	const toggleCollapsed = () => {
		const next = !collapsed;
		setCollapsed(next);
		try {
			window.localStorage.setItem(STORAGE_KEY, String(next));
		} catch {
			// Navigation remains available when browser storage is blocked.
		}
	};
	const toggleMobileMenu: VoidFunction = () => setMobileMenuOpen(open => !open);

	useEffect(() =>
	{
		setMobileMenuOpen(false);
	}, [route, activePageName]);

	useEffect(() =>
	{
		if (!window.matchMedia) return undefined;

		const media = window.matchMedia(MOBILE_QUERY);
		const closeMobileMenu = () => setMobileMenuOpen(false);
		media.addEventListener('change', closeMobileMenu);

		return () => media.removeEventListener('change', closeMobileMenu);
	}, []);
	const groups = [
		...(administers ? [{
			route: '/', title: __('Dashboard', 'translate'), icon: TranslateWPIcon,
			pages: DASHBOARD_PAGES,
		}] : []),
		...(administers ? [{
			route: '/seo', title: __('SEO Tools', 'translate'), icon: Search, pages: [],
		}] : []),
		...(administers || can.lexicon ? [{
			route: '/lexicon', title: __('Lexicon', 'translate'), icon: BookOpen, pages: [],
		}] : []),
		...(administers || can.settings ? [{
			route: '/settings', title: __('Settings', 'translate'), icon: Settings,
			pages: settingsPages,
		}] : []),
	];
	const tooltipPlacement = isRTL() ? 'left' : 'right';
	const ToggleIcon = collapsed ? PanelLeftOpen : PanelLeftClose;
	const toggleLabel = collapsed
		? __('Expand sidebar', 'translate') : __('Collapse sidebar', 'translate');
	const activeGroup = groups.find(group => group.route === route);
	const activePage = activeGroup?.pages.find(page => page.name === activePageName);
	const activeTitle = activePage
		? (activePage.name === 'ai-model' ? __('AI models', 'translate') : activePage.title)
		: activeGroup?.title ?? __('Dashboard', 'translate');
	const mobileToggleLabel = sprintf(
		/* translators: %s: current TranslateWP admin page name. */
		__('Menu, current page: %s', 'translate'),
		activeTitle
	);

	return (
		<aside
			className={`sticky top-12 box-border max-h-[calc(100vh-64px)] overflow-y-auto
				rounded-lg border border-solid border-translatewp-border bg-white p-3 [&_svg]:shrink-0
				[@media(max-width:782px)]:static [@media(max-width:782px)]:w-full [@media(max-width:782px)]:basis-auto [@media(max-width:782px)]:max-h-none
				${collapsed ? 'flex-[0_0_66px]' : 'flex-[0_0_224px]'}`}
			data-collapsed={collapsed}
		>
			<Button
				className={`flex! min-h-8! items-center gap-[9px]! box-border w-full rounded-md! px-2.5! py-1.5!
					text-xs! font-normal! leading-4! text-translatewp-muted! hover:text-translatewp-primary!
					focus:text-translatewp-primary! mb-3! [@media(max-width:782px)]:hidden!
					${collapsed ? 'justify-center! p-2!' : ''}`}
				onClick={toggleCollapsed}
				label={toggleLabel}
				aria-expanded={!collapsed}
				aria-controls='twp-sidebar-navigation'
			>
				<ToggleIcon size={16} aria-hidden='true' />
				<span className={collapsed ? 'hidden' : undefined}>{toggleLabel}</span>
			</Button>
			<Button
				className='hidden! min-h-11! w-full items-center justify-between rounded-md! px-2.5! py-2!
					text-translatewp-neutral! [@media(max-width:782px)]:flex!'
				onClick={toggleMobileMenu}
				label={mobileToggleLabel}
				aria-expanded={mobileMenuOpen}
				aria-controls='twp-sidebar-navigation'
			>
				<span className='flex items-baseline gap-1.5' aria-hidden='true'>
					<span className='text-xs font-normal text-translatewp-muted'>{__('Menu', 'translate')}</span>
					<span className='text-xs font-normal text-translatewp-muted'>·</span>
					<span className='text-sm font-semibold text-translatewp-neutral'>{activeTitle}</span>
				</span>
				<ChevronDown
					size={18}
					className={mobileMenuOpen ? 'rotate-180' : undefined}
					aria-hidden='true'
				/>
			</Button>
			<nav
				className={mobileMenuOpen ? undefined : '[@media(max-width:782px)]:hidden'}
				id='twp-sidebar-navigation' aria-label={__('TranslateWP navigation', 'translate')}>
				{groups.map(group => (
					<div className='mt-2 pt-2 [border-top:1px_solid_var(--color-translatewp-border)]' key={group.route}>
						<Tooltip text={collapsed ? group.title : undefined} placement={tooltipPlacement}>
							<a
								href={buildAdminUrl(group.route)}
								className={`${LINK_CLASS} font-semibold text-translatewp-neutral!`}
								aria-current={route === group.route ? 'page' : undefined}
								aria-label={group.title}
								onClick={() => setMobileMenuOpen(false)}
							>
								{group.route === '/'
									? <TranslateWPIcon size={ICON_SIZE} tone='current' />
									: <group.icon size={ICON_SIZE} aria-hidden='true' />}
								<span className={`${collapsed ? 'hidden' : 'inline-flex'} items-center gap-2 [@media(max-width:782px)]:inline-flex!`}>
									{group.title}
									{group.route === '/seo' && <ProBadge />}
								</span>
							</a>
						</Tooltip>
						{group.pages.map(page => {
							const Icon = PAGE_ICONS[page.name as keyof typeof PAGE_ICONS];
							const title = page.name === 'ai-model' ? __('AI models', 'translate') : page.title;
							return (
								<Tooltip
									key={page.name}
									text={collapsed ? title : undefined}
									placement={tooltipPlacement}
								>
									<a
										href={buildAdminUrl(group.route, page.name)}
										className={`${LINK_CLASS} text-translatewp-neutral! [@media(max-width:782px)]:ps-[34px]! ${collapsed ? 'ps-2.5!' : 'ps-[34px]!'}`}
										aria-current={route === group.route && activePageName === page.name
											? 'location' : undefined}
										aria-label={title}
										onClick={() => setMobileMenuOpen(false)}
									>
										<Icon size={ICON_SIZE} aria-hidden='true' />
										<span className={`${collapsed ? 'hidden' : ''} [@media(max-width:782px)]:inline!`}>{title}</span>
									</a>
								</Tooltip>
							);
						})}
					</div>
				))}
			</nav>
			<div className={`mt-2 pt-2 [border-top:1px_solid_var(--color-translatewp-border)] text-xs text-center text-translatewp-muted ${mobileMenuOpen ? '' : '[@media(max-width:782px)]:hidden'}`}>
				<a
					href='https://codesnippets.pro/' target='_blank' rel='noopener noreferrer'
					className='inline-flex items-center gap-2 text-translatewp-muted! no-underline!'
					aria-label={__('A product of Code Snippets', 'translate')}
				>
					<span className={`${collapsed ? 'hidden' : ''} [@media(max-width:782px)]:inline!`}>{__('A product of', 'translate')}</span>
					<img src={codeSnippetsLogo} alt='Code Snippets' width={24} height={20} />
				</a>
			</div>
		</aside>
	);
};

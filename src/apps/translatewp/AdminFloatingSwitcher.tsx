import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useMutation } from '@tanstack/react-query';
import type { PointerEvent } from 'react';

import { SWITCHER_MOBILE_WIDTH } from '@/shared/lib/switcherPosition';
import type { FloatingSwitcherPosition, SideTabPosition, SwitcherDevice } from '@/shared/lib/switcherPosition';
import { switcherPositionMutationOptions } from '@/shared/mutation-options/switcherPosition';
import { useEditMode } from '@/apps/translatewp/editMode';
import { SwitcherView, type SwitcherViewProps } from './SwitcherView';
import { hasDragMovement, positionFromDrop } from './switcherDrag';
import { resolveSideTabPosition, sideTabFromDrop } from './sideTab';
import { dragHint } from './dragHint';
import { managerCan } from './managerAccess';

type Gesture = {
	pointerId: number;
	button: HTMLElement;
	x: number;
	y: number;
	rect: DOMRect;
	dragging: boolean;
	device: SwitcherDevice;
	position?: Place;
};

/** A drag moves the place of the type the switcher is: the floating button's corner, or the side tab's edge. */
type Place = FloatingSwitcherPosition | SideTabPosition;

const deviceOf = (): SwitcherDevice => window.innerWidth > SWITCHER_MOBILE_WIDTH ? 'desktop' : 'mobile';

export default function AdminFloatingSwitcher(props: SwitcherViewProps)
{
	const gesture = useRef<Gesture | null>(null);
	// The last place the owner let go of, per screen width; it is what shows,
	// whatever the save does.
	const sideTab = props.switcher.type === 'side_tab';
	const confirmed = useRef<Record<SwitcherDevice, Place | undefined>>(sideTab
		? { desktop: resolveSideTabPosition(props.switcher, 'desktop'), mobile: resolveSideTabPosition(props.switcher, 'mobile') }
		: { desktop: props.switcher.floating_position, mobile: props.switcher.floating_position_mobile });
	const revision = useRef(window.TranslateWP.context.settings_revision);
	const suppressClick = useRef(false);
	const mounted = useRef(true);
	const [preview, setPreview] = useState<{ device: SwitcherDevice; position: Place }>();
	const [, setDropped] = useState(0);
	const [error, setError] = useState<string | null>(null);
	const mutation = useMutation(switcherPositionMutationOptions());
	const editing = useEditMode();
	// Moving the switcher changes a switcher setting.
	const enabled = editing && managerCan().settings
		&& props.variant !== 'inline' && props.switcher.type !== 'native_select';

	const release = () =>
	{
		const current = gesture.current;
		gesture.current = null;
		if (current?.button.hasPointerCapture(current.pointerId))
		{
			current.button.releasePointerCapture(current.pointerId);
		}
	};
	const cancel = () =>
	{
		if (!gesture.current) return;
		suppressClick.current = gesture.current.dragging;
		release();
		setPreview(undefined);
	};

	useEffect(() =>
	{
		mounted.current = true;
		const escape = (event: KeyboardEvent) =>
		{
			if (event.key === 'Escape') cancel();
		};
		// Rotating a phone or resizing the window mid-drag ends the drag.
		window.addEventListener('resize', cancel);
		document.addEventListener('keydown', escape);
		return () =>
		{
			mounted.current = false;
			release();
			window.removeEventListener('resize', cancel);
			document.removeEventListener('keydown', escape);
		};
	}, []);

	const start = (event: PointerEvent<HTMLElement>) =>
	{
		if (!enabled || gesture.current || event.button !== 0 || !event.isPrimary) return;
		suppressClick.current = false;
		// The move badge forwards its pointer events here; the moved box is
		// always the switcher, whichever of the two was grabbed.
		const trigger = (event.currentTarget.closest('.twp-container')?.querySelector('.twp-trigger') as HTMLElement | null) ?? event.currentTarget;
		gesture.current = {
			pointerId: event.pointerId, button: event.currentTarget,
			x: event.clientX, y: event.clientY, rect: trigger.getBoundingClientRect(),
			dragging: false, device: deviceOf(),
		};
		event.currentTarget.setPointerCapture(event.pointerId);
	};

	const move = (event: PointerEvent<HTMLElement>) =>
	{
		const current = gesture.current;
		if (!current || current.pointerId !== event.pointerId) return;
		const x = event.clientX - current.x;
		const y = event.clientY - current.y;
		if (!current.dragging && !hasDragMovement({ x, y })) return;
		current.dragging = true;
		event.preventDefault();
		const bar = document.getElementById('wpadminbar');
		// The page's own width and height, without a scrollbar: the switcher
		// is placed against these, so measuring against the window would
		// leave it a scrollbar's width away from the pointer.
		const root = document.documentElement;
		const drop = {
			left: current.rect.left + x, top: current.rect.top + y,
			width: current.rect.width, height: current.rect.height,
			viewportWidth: root.clientWidth, viewportHeight: root.clientHeight,
			adminBarHeight: bar && getComputedStyle(bar).position === 'fixed' ? bar.getBoundingClientRect().height : 0,
		};
		current.position = sideTab ? sideTabFromDrop({ ...drop, device: current.device }) : positionFromDrop(drop);
		setPreview({ device: current.device, position: current.position });
	};

	const finish = (event: PointerEvent<HTMLElement>) =>
	{
		const current = gesture.current;
		if (!current || current.pointerId !== event.pointerId) return;
		suppressClick.current = current.dragging;
		release();
		if (!current.dragging || !current.position) return;
		// The place the user let go of is the truth: it stays whatever the
		// save does. The save is best effort; a failure is said quietly and
		// the next drop tries again.
		confirmed.current = { ...confirmed.current, [current.device]: current.position };
		// Someone who has dragged it knows it can be dragged.
		dragHint.finish();
		setDropped(count => count + 1);
		setPreview(undefined);
		setError(null);
		const input = sideTab
			? { kind: 'side_tab' as const, position: current.position as SideTabPosition, device: current.device, revision: revision.current }
			: { position: current.position as FloatingSwitcherPosition, device: current.device, revision: revision.current };
		mutation.mutate(input, {
			onSuccess: result =>
			{
				if (!mounted.current) return;
				revision.current = result.revision;
			},
			onError: () =>
			{
				if (!mounted.current) return;
				setError(__('The switcher position could not be saved; it stays here for now.', 'translate'));
			},
		});
	};

	const shown = (device: SwitcherDevice) => preview?.device === device ? preview.position : confirmed.current[device];

	const placed = sideTab
		? { side_tab_position: shown('desktop') as SideTabPosition, side_tab_position_mobile: shown('mobile') as SideTabPosition }
		: { floating_position: shown('desktop') as FloatingSwitcherPosition, floating_position_mobile: shown('mobile') as FloatingSwitcherPosition };
	return <SwitcherView {...props}
		switcher={{ ...props.switcher, ...placed }}
		notice={error ?? props.notice}
		onDismissNotice={() => { setError(null); props.onDismissNotice(); }}
		triggerBindings={enabled ? {
			'data-drag-handle': true,
			'data-dragging': !!preview || undefined,
			title: __('Drag to move the language switcher. Click to choose a language.', 'translate'),
			onPointerDown: start, onPointerMove: move, onPointerUp: finish,
			onPointerCancel: cancel, onLostPointerCapture: cancel,
			onClickCapture: event =>
			{
				if (!suppressClick.current) return;
				suppressClick.current = false;
				event.preventDefault(); event.stopPropagation();
			},
		} : undefined}
	/>;
}

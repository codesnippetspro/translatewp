import { useId } from '@wordpress/element';
import type { ReactNode } from 'react';

import {
	OPTION_CARD_CLASS,
	OPTION_GRID_CLASS,
} from '@/apps/admin/components/Languages/Switcher/optionGridClass';
import { OptionRow } from '@/apps/admin/components/Languages/Switcher/OptionRow';
import { ProLock } from '@/shared/components/pro/ProLock';
import { ProMarker } from '@/shared/components/pro/ProMarker';

export interface OptionCard<Value extends string>
{
	value: Value;
	label: string;
	description: string;
	example?: ReactNode;
	locked?: { feature: string };
}

export interface OptionCardsProps<Value extends string>
{
	options: OptionCard<Value>[];
	value: Value;
	onChange: (value: Value) => void;
	columns?: 1 | 2 | 3;
	name?: string;
	label: string;
}

export const OptionCards = <Value extends string>({
	options,
	value,
	onChange,
	columns = 3,
	name,
	label,
}: OptionCardsProps<Value>) =>
{
	const id = useId();
	const gridClassName = {
		1: 'grid w-full min-w-0 grid-cols-1 gap-3 [&>*]:min-w-0',
		2: 'grid w-full min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 [&>*]:min-w-0',
		3: OPTION_GRID_CLASS,
	}[columns];

	return (
		<div className={gridClassName} role="radiogroup" aria-label={label} data-option-cards={name}>
			{options.map((option) =>
			{
				const card = (
					<div
						key={option.value}
						className={`${OPTION_CARD_CLASS} relative transition-colors
							focus-within:outline-2! focus-within:outline-solid!
							focus-within:outline-offset-2! focus-within:outline-translatewp-primary! ${
							value === option.value
								? 'border-translatewp-primary! bg-translatewp-primary/5'
								: 'border-translatewp-border! bg-white hover:border-translatewp-primary/40'
						}`}
					>
						<input
							type="radio"
							name={`translatewp-option-${id}`}
							value={option.value}
							checked={value === option.value}
							disabled={Boolean(option.locked)}
							aria-label={option.label}
							aria-describedby={`${id}-${option.value}-description`}
							onChange={() => onChange(option.value)}
							className="absolute inset-0 m-0! h-full! w-full! cursor-pointer opacity-0!"
						/>
						<OptionRow
							label={option.label}
							description={option.description}
							descriptionId={`${id}-${option.value}-description`}
							isSelected={value === option.value}
						/>
						{option.example && (
							<div className="mt-3 flex items-center gap-2" data-option-example>
								{option.example}
							</div>
						)}
					</div>
				);
				return option.locked ? (
					<ProLock
						key={option.value}
						feature={option.locked.feature}
						badge={<ProMarker />}
						badgeClassName="end-4 top-4"
						className="h-full"
					>
						{card}
					</ProLock>
				) : card;
			})}
		</div>
	);
};

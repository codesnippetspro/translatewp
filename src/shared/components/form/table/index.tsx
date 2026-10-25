import { type ComponentPropsWithoutRef, type ElementType, type PropsWithChildren } from 'react';

const joinClassNames = (...classes: Array<string | false | null | undefined>) =>
	classes.filter(Boolean).join(' ');

export type TableProps = ComponentPropsWithoutRef<'table'>;

export const Table = ({ className, children, ...props }: TableProps) => (
	<div className="max-w-full overflow-x-auto rounded-lg border border-translatewp-border">
		<table
			className={joinClassNames('w-full table-fixed border-separate border-spacing-0', className)}
			{...props}
		>
			<tbody>{children}</tbody>
		</table>
	</div>
);

export interface TableRowProps extends ComponentPropsWithoutRef<'tr'> {
	variant?: 'header' | 'body';
}

export const TableRow = ({ variant = 'body', className, ...props }: TableRowProps) => (
	<tr
		className={joinClassNames(
			'[&>*]:border-b [&>*]:border-translatewp-border last:[&>*]:border-b-0',
			variant === 'header' && 'bg-translatewp-selection',
			className
		)}
		{...props}
	/>
);

export type TableHeaderRowProps = Omit<TableRowProps, 'variant'>;

export const TableHeaderRow = (props: TableHeaderRowProps) => (
	<TableRow {...props} variant="header" />
);

export type TableCellProps<T extends ElementType = 'span'> = PropsWithChildren<{
	as?: T;
	align?: 'start' | 'end' | 'left' | 'center' | 'right';
	variant?: 'header' | 'body';
	className?: string;
	colSpan?: number;
}> & Omit<ComponentPropsWithoutRef<T>, 'as' | 'className' | 'children'>;

export const TableCell = <T extends ElementType = 'span'>({
	as,
	align = 'start',
	variant = 'body',
	className,
	children,
	colSpan,
	...props
}: TableCellProps<T>) => {
	const Cell = variant === 'header' ? 'th' : 'td';
	const Content = as ?? 'span';
	const alignClassName = {
		start: 'text-start',
		end: 'text-end',
		left: 'text-left',
		center: 'text-center',
		right: 'text-right',
	}[align];

	return (
		<Cell
			scope={variant === 'header' ? 'col' : undefined}
			colSpan={colSpan}
			className={joinClassNames(
				'px-3 py-4 align-middle text-sm text-translatewp-muted break-words',
				variant === 'header' && 'font-semibold',
				alignClassName,
				!as && className
			)}
			{...props}
		>
			{as ? <Content className={className}>{children}</Content> : children}
		</Cell>
	);
};

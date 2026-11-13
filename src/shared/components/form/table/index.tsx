import {
	type ComponentPropsWithoutRef,
	type ElementType,
	type PropsWithChildren,
	type ReactNode,
} from 'react';

const joinClassNames = (...classes: Array<string | false | null | undefined>) =>
	classes.filter(Boolean).join(' ');

export interface TableProps extends ComponentPropsWithoutRef<'table'> {
	containerProps?: ComponentPropsWithoutRef<'div'>;
	header?: ReactNode;
}

export const Table = ({ className, children, containerProps, header, ...props }: TableProps) => {
	const { className: containerClassName, ...containerAttributes } = containerProps ?? {};

	return (
		<div
			className={joinClassNames(
				'relative max-w-full overflow-x-auto rounded-lg border border-translatewp-border',
				containerClassName
			)}
			{...containerAttributes}
		>
			<table
				className={joinClassNames('w-full table-auto border-separate border-spacing-0', className)}
				{...props}
			>
				{header && <thead>{header}</thead>}
				<tbody>{children}</tbody>
			</table>
		</div>
	);
};

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
	variant?: 'header' | 'body' | 'row-header';
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
	const Cell = variant === 'body' ? 'td' : 'th';
	const Content = as ?? 'span';
	const isHeader = variant === 'header';
	const isRowHeader = variant === 'row-header';
	const alignClassName = {
		start: 'text-start',
		end: 'text-end',
		left: 'text-left',
		center: 'text-center',
		right: 'text-right',
	}[align];

	return (
		<Cell
			scope={isHeader ? 'col' : isRowHeader ? 'row' : undefined}
			colSpan={colSpan}
			className={joinClassNames(
				'px-3 py-4 align-middle text-sm text-translatewp-muted break-words',
				isHeader && 'font-semibold whitespace-nowrap',
				isRowHeader && 'font-semibold text-translatewp-neutral',
				alignClassName,
				!as && className
			)}
			{...props}
		>
			{as ? <Content className={className}>{children}</Content> : children}
		</Cell>
	);
};

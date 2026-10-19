import { type ComponentPropsWithoutRef, type ElementType, type PropsWithChildren } from 'react';

const joinClassNames = (...classes: Array<string | false | null | undefined>) =>
    classes.filter(Boolean).join(' ');

export type TableProps = ComponentPropsWithoutRef<'div'>;

export const Table = ({ className, ...props }: TableProps) => (
    <div
        className={joinClassNames(
            'flex flex-col border border-translatewp-border rounded-lg',
            className
        )}
        {...props}
    />
);

export interface TableRowProps extends ComponentPropsWithoutRef<'div'> {
    columnsClassName?: string;
    variant?: 'header' | 'body';
}

export const TableRow = ({
    columnsClassName = 'grid-cols-5',
    variant = 'body',
    className,
    ...props
}: TableRowProps) => {
    const variantClassName =
        variant === 'header'
            ? 'bg-translatewp-selection border-b'
            : 'border-b last:border-b-0';

    return (
        <div
            className={joinClassNames(
                'grid justify-between items-center px-3 py-4 border-translatewp-border',
                columnsClassName,
                variantClassName,
                className
            )}
            {...props}
        />
    );
};

export type TableHeaderRowProps = Omit<TableRowProps, 'variant'>;

export const TableHeaderRow = (props: TableHeaderRowProps) => (
    <TableRow {...props} variant="header" />
);

export type TableCellProps<T extends ElementType = 'span'> = PropsWithChildren<{
    as?: T;
    align?: 'left' | 'center' | 'right';
    variant?: 'header' | 'body';
    className?: string;
}> & Omit<ComponentPropsWithoutRef<T>, 'as' | 'className' | 'children'>;

export const TableCell = <T extends ElementType = 'span'>({
    as,
    align = 'left',
    variant = 'body',
    className,
    ...props
}: TableCellProps<T>) => {
    const Component = as ?? 'span';
    const alignClassName =
        align === 'center' ? 'text-center' : align === 'right' ? 'text-right' : 'text-left';
    const variantClassName =
        variant === 'header'
            ? 'font-semibold text-sm text-translatewp-muted'
            : 'text-sm text-translatewp-muted';

    return (
        <Component
            className={joinClassNames(variantClassName, alignClassName, className)}
            {...props}
        />
    );
};

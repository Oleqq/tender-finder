import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode } from 'react';
import { Icon, type IconName } from './Icon';

const join = (...classes: Array<string | false | null | undefined>): string =>
    classes.filter(Boolean).join(' ');

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
    children: ReactNode;
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
    size?: 'sm' | 'md' | 'lg';
    icon?: IconName;
};

export function Button({
    children,
    className,
    variant = 'primary',
    size = 'md',
    icon,
    type = 'button',
    ...props
}: ButtonProps) {
    return (
        <button
            className={join(
                'button',
                `button--${variant}`,
                `button--${size}`,
                className,
            )}
            type={type}
            {...props}
        >
            {icon ? <Icon name={icon} size={size === 'sm' ? 17 : 19} /> : null}
            <span>{children}</span>
        </button>
    );
}

type GlassCardProps = {
    children: ReactNode;
    className?: string;
    tone?: 'default' | 'accent' | 'quiet' | 'danger';
    as?: 'article' | 'section' | 'div';
};

export function GlassCard({
    children,
    className,
    tone = 'default',
    as: Element = 'section',
}: GlassCardProps) {
    return (
        <Element className={join('glass-card', `glass-card--${tone}`, className)}>
            {children}
        </Element>
    );
}

export function Badge({
    children,
    tone = 'neutral',
}: {
    children: ReactNode;
    tone?: 'neutral' | 'accent' | 'success' | 'warning' | 'danger';
}) {
    return <span className={join('badge', `badge--${tone}`)}>{children}</span>;
}

export function SearchInput({
    className,
    ...props
}: InputHTMLAttributes<HTMLInputElement>) {
    return (
        <label className={join('search-input', className)}>
            <Icon name="search" size={19} />
            <input type="search" placeholder="Поиск по тендерам" {...props} />
        </label>
    );
}

export function FilterChip({
    children,
    active = false,
    onClick,
}: {
    children: ReactNode;
    active?: boolean;
    onClick?: () => void;
}) {
    return (
        <button
            className={join('filter-chip', active && 'is-active')}
            onClick={onClick}
            type="button"
        >
            {children}
        </button>
    );
}

export function SegmentedControl({
    options,
    value,
    onChange,
    label,
}: {
    options: Array<{ value: string; label: string }>;
    value: string;
    onChange: (value: string) => void;
    label: string;
}) {
    return (
        <div aria-label={label} className="segmented-control" role="group">
            {options.map((option) => (
                <button
                    aria-pressed={value === option.value}
                    className={join(
                        'segmented-control__item',
                        value === option.value && 'is-active',
                    )}
                    key={option.value}
                    onClick={() => onChange(option.value)}
                    type="button"
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

export function Toggle({
    checked,
    onChange,
    label,
    description,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    label: string;
    description?: string;
}) {
    return (
        <label className="toggle-row">
            <span>
                <span className="toggle-row__label">{label}</span>
                {description ? (
                    <span className="toggle-row__description">{description}</span>
                ) : null}
            </span>
            <input
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
                type="checkbox"
            />
            <span aria-hidden="true" className="toggle" />
        </label>
    );
}

export function Skeleton({ className }: { className?: string }) {
    return <span aria-busy="true" className={join('skeleton', className)} />;
}

export function EmptyState({
    icon = 'compass',
    title,
    description,
    action,
}: {
    icon?: IconName;
    title: string;
    description: string;
    action?: ReactNode;
}) {
    return (
        <section className="empty-state">
            <span className="empty-state__icon">
                <Icon name={icon} size={27} />
            </span>
            <h2>{title}</h2>
            <p>{description}</p>
            {action ? <div className="empty-state__action">{action}</div> : null}
        </section>
    );
}

export function BottomSheet({
    children,
    open,
    onClose,
    title,
}: {
    children: ReactNode;
    open: boolean;
    onClose: () => void;
    title: string;
}) {
    if (!open) {
        return null;
    }

    return (
        <div
            aria-modal="true"
            className="sheet-layer"
            onMouseDown={onClose}
            role="dialog"
        >
            <section
                className="bottom-sheet"
                onMouseDown={(event) => event.stopPropagation()}
            >
                <div className="bottom-sheet__handle" />
                <div className="bottom-sheet__heading">
                    <h2>{title}</h2>
                    <button
                        aria-label="Закрыть"
                        className="icon-button"
                        onClick={onClose}
                        type="button"
                    >
                        ×
                    </button>
                </div>
                {children}
            </section>
        </div>
    );
}

export function Toast({
    message,
    visible,
    tone = 'success',
}: {
    message: string;
    visible: boolean;
    tone?: 'success' | 'neutral';
}) {
    return (
        <div
            aria-live="polite"
            className={join('toast', `toast--${tone}`, visible && 'is-visible')}
        >
            <Icon name={tone === 'success' ? 'check' : 'spark'} size={18} />
            <span>{message}</span>
        </div>
    );
}

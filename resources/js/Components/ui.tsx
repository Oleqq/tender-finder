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

export function InlineAlert({
    children,
    title,
    tone = 'neutral',
}: {
    children: ReactNode;
    title: string;
    tone?: 'neutral' | 'success' | 'warning' | 'danger';
}) {
    const icon = tone === 'success' ? 'check' : tone === 'warning' ? 'spark' : 'shield';

    return (
        <aside className={join('inline-alert', `inline-alert--${tone}`)}>
            <Icon name={icon} size={18} />
            <div>
                <strong>{title}</strong>
                <p>{children}</p>
            </div>
        </aside>
    );
}

export function ProgressBar({
    label,
    value,
    max = 100,
    detail,
}: {
    label: string;
    value: number;
    max?: number;
    detail?: string;
}) {
    const percentage = Math.max(0, Math.min(100, (value / max) * 100));

    return (
        <div className="progress-bar">
            <div className="progress-bar__heading">
                <span>{label}</span>
                {detail ? <strong>{detail}</strong> : null}
            </div>
            <div
                aria-label={label}
                aria-valuemax={max}
                aria-valuemin={0}
                aria-valuenow={value}
                className="progress-bar__track"
                role="progressbar"
            >
                <span style={{ width: `${percentage}%` }} />
            </div>
        </div>
    );
}

export function DataRow({
    label,
    value,
    detail,
    icon,
}: {
    label: string;
    value: ReactNode;
    detail?: string;
    icon?: IconName;
}) {
    return (
        <div className="data-row">
            {icon ? (
                <span className="data-row__icon">
                    <Icon name={icon} size={18} />
                </span>
            ) : null}
            <span className="data-row__copy">
                <strong>{label}</strong>
                {detail ? <small>{detail}</small> : null}
            </span>
            <span className="data-row__value">{value}</span>
        </div>
    );
}

export function AccessGate({
    title,
    description,
    action,
}: {
    title: string;
    description: string;
    action: ReactNode;
}) {
    return (
        <section className="access-gate">
            <span className="access-gate__icon">
                <Icon name="shield" size={22} />
            </span>
            <div>
                <p>Следующий уровень</p>
                <h2>{title}</h2>
                <span>{description}</span>
            </div>
            <div className="access-gate__action">{action}</div>
        </section>
    );
}

export function PlanCard({
    name,
    description,
    features,
    price,
    badge,
    action,
    featured = false,
}: {
    name: string;
    description: string;
    features: string[];
    price: string;
    badge?: ReactNode;
    action: ReactNode;
    featured?: boolean;
}) {
    return (
        <GlassCard
            as="article"
            className={join('plan-card', featured && 'plan-card--featured')}
            tone={featured ? 'accent' : 'default'}
        >
            <div className="plan-card__heading">
                <div>
                    <p>{badge ?? 'План доступа'}</p>
                    <h2>{name}</h2>
                </div>
                <strong>{price}</strong>
            </div>
            <p className="plan-card__description">{description}</p>
            <ul>
                {features.map((feature) => (
                    <li key={feature}>
                        <Icon name="check" size={16} />
                        <span>{feature}</span>
                    </li>
                ))}
            </ul>
            <div className="plan-card__action">{action}</div>
        </GlassCard>
    );
}

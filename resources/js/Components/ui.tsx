import type {
    ButtonHTMLAttributes,
    InputHTMLAttributes,
    ReactNode,
    SelectHTMLAttributes,
} from 'react';
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

export function MetricTrend({
    value,
    direction = 'up',
    label,
}: {
    value: string;
    direction?: 'up' | 'down' | 'neutral';
    label?: string;
}) {
    return (
        <span
            aria-label={label}
            className={join('metric-trend', `metric-trend--${direction}`)}
        >
            {direction === 'up' ? '↗' : direction === 'down' ? '↘' : '•'} {value}
        </span>
    );
}

export function MetricCardSkeleton() {
    return (
        <section
            aria-label="Загрузка метрики"
            className="glass-card metric-card metric-card--skeleton"
        >
            <Skeleton className="skeleton--metric-icon" />
            <Skeleton className="skeleton--metric-label" />
            <Skeleton className="skeleton--metric-value" />
            <Skeleton className="skeleton--metric-detail" />
        </section>
    );
}

export function TenderCardSkeleton() {
    return (
        <section
            aria-label="Загрузка тендера"
            className="glass-card tender-card tender-card--skeleton"
        >
            <div className="tender-card__meta">
                <Skeleton className="skeleton--badge" />
                <Skeleton className="skeleton--match" />
            </div>
            <Skeleton className="skeleton--tender-title" />
            <Skeleton className="skeleton--tender-customer" />
            <div className="tender-card__footer">
                <Skeleton className="skeleton--tender-price" />
                <Skeleton className="skeleton--tender-deadline" />
            </div>
        </section>
    );
}

export function DataRowSkeleton() {
    return (
        <div
            aria-label="Загрузка строки данных"
            className="data-row data-row--skeleton"
        >
            <Skeleton className="skeleton--data-icon" />
            <span className="data-row__copy">
                <Skeleton className="skeleton--data-label" />
                <Skeleton className="skeleton--data-detail" />
            </span>
            <Skeleton className="skeleton--data-value" />
        </div>
    );
}

export function SelectField({
    label,
    options,
    error,
    className,
    ...props
}: SelectHTMLAttributes<HTMLSelectElement> & {
    label: string;
    options: Array<{ value: string; label: string }>;
    error?: string;
}) {
    const fieldId = props.id ?? `select-${label}`;

    return (
        <label className={join('form-field', error && 'has-error', className)}>
            <span>{label}</span>
            <select
                aria-describedby={error ? `${fieldId}-error` : undefined}
                id={fieldId}
                {...props}
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            {error ? <FieldError id={`${fieldId}-error`}>{error}</FieldError> : null}
        </label>
    );
}

export function Combobox({
    label,
    value,
    onChange,
    options,
    placeholder,
    error,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: string[];
    placeholder?: string;
    error?: string;
}) {
    const listId = `combobox-${label}`;

    return (
        <label className={join('form-field', error && 'has-error')}>
            <span>{label}</span>
            <input
                aria-describedby={error ? `${listId}-error` : undefined}
                aria-expanded="false"
                autoComplete="off"
                list={listId}
                onChange={(event) => onChange(event.target.value)}
                placeholder={placeholder}
                role="combobox"
                value={value}
            />
            <datalist id={listId}>
                {options.map((option) => (
                    <option key={option} value={option} />
                ))}
            </datalist>
            {error ? <FieldError id={`${listId}-error`}>{error}</FieldError> : null}
        </label>
    );
}

export function MultiSelect({
    label,
    options,
    selected,
    onChange,
    error,
}: {
    label: string;
    options: string[];
    selected: string[];
    onChange: (value: string[]) => void;
    error?: string;
}) {
    const toggle = (option: string): void => {
        onChange(
            selected.includes(option)
                ? selected.filter((item) => item !== option)
                : [...selected, option],
        );
    };

    return (
        <fieldset
            className={join('form-field', 'form-field--multi', error && 'has-error')}
        >
            <legend>{label}</legend>
            <div className="multi-select" role="group">
                {options.map((option) => (
                    <button
                        aria-pressed={selected.includes(option)}
                        className={join(
                            'multi-select__option',
                            selected.includes(option) && 'is-selected',
                        )}
                        key={option}
                        onClick={() => toggle(option)}
                        type="button"
                    >
                        {selected.includes(option) ? (
                            <Icon name="check" size={14} />
                        ) : null}
                        {option}
                    </button>
                ))}
            </div>
            {error ? <FieldError>{error}</FieldError> : null}
        </fieldset>
    );
}

export function DateRangeInput({
    start,
    end,
    onStartChange,
    onEndChange,
}: {
    start: string;
    end: string;
    onStartChange: (value: string) => void;
    onEndChange: (value: string) => void;
}) {
    return (
        <fieldset className="form-field form-field--range">
            <legend>Срок подачи</legend>
            <div className="range-inputs">
                <label>
                    <span>От</span>
                    <input
                        onChange={(event) => onStartChange(event.target.value)}
                        type="date"
                        value={start}
                    />
                </label>
                <label>
                    <span>До</span>
                    <input
                        min={start || undefined}
                        onChange={(event) => onEndChange(event.target.value)}
                        type="date"
                        value={end}
                    />
                </label>
            </div>
        </fieldset>
    );
}

export function MoneyRangeInput({
    min,
    max,
    onMinChange,
    onMaxChange,
}: {
    min: string;
    max: string;
    onMinChange: (value: string) => void;
    onMaxChange: (value: string) => void;
}) {
    return (
        <fieldset className="form-field form-field--range">
            <legend>Бюджет, ₽</legend>
            <div className="range-inputs">
                <label>
                    <span>От</span>
                    <input
                        inputMode="numeric"
                        min="0"
                        onChange={(event) => onMinChange(event.target.value)}
                        placeholder="0"
                        type="number"
                        value={min}
                    />
                </label>
                <label>
                    <span>До</span>
                    <input
                        inputMode="numeric"
                        min={min || '0'}
                        onChange={(event) => onMaxChange(event.target.value)}
                        placeholder="Без лимита"
                        type="number"
                        value={max}
                    />
                </label>
            </div>
        </fieldset>
    );
}

export function FieldError({ children, id }: { children: ReactNode; id?: string }) {
    return (
        <span className="field-error" id={id} role="alert">
            {children}
        </span>
    );
}

export function RetryState({
    title,
    description,
    action,
    tone = 'error',
}: {
    title: string;
    description: string;
    action: ReactNode;
    tone?: 'error' | 'offline';
}) {
    const icon = tone === 'offline' ? 'wave' : 'refresh';

    return (
        <section className={join('retry-state', `retry-state--${tone}`)}>
            <span className="retry-state__icon">
                <Icon name={icon} size={22} />
            </span>
            <div>
                <h2>{title}</h2>
                <p>{description}</p>
            </div>
            <div className="retry-state__action">{action}</div>
        </section>
    );
}

export function CheckoutState({
    title,
    description,
    icon = 'spark',
    tone = 'neutral',
    children,
    loading = false,
}: {
    title: string;
    description: string;
    icon?: IconName;
    tone?: 'neutral' | 'success' | 'warning' | 'danger';
    children?: ReactNode;
    loading?: boolean;
}) {
    return (
        <section className={join('checkout-state', `checkout-state--${tone}`)}>
            <span className="checkout-state__icon">
                <Icon name={icon} size={22} />
            </span>
            <div>
                <h3>{title}</h3>
                <p>{description}</p>
            </div>
            {loading ? (
                <div
                    aria-label="Загрузка checkout-состояния"
                    className="checkout-state__loading"
                >
                    <Skeleton className="skeleton--checkout-title" />
                    <Skeleton className="skeleton--checkout-detail" />
                </div>
            ) : null}
            {children ? <div className="checkout-state__action">{children}</div> : null}
        </section>
    );
}

export function PlanComparison({
    rows,
}: {
    rows: Array<{ feature: string; basic: ReactNode; pro: ReactNode }>;
}) {
    return (
        <section
            className="plan-comparison"
            role="region"
            aria-label="Сравнение планов"
        >
            <p className="plan-comparison__hint">
                Проведите таблицу вбок, чтобы увидеть PRO
            </p>
            <div className="plan-comparison__scroll">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Возможность</th>
                            <th scope="col">Basic</th>
                            <th scope="col">PRO</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.feature}>
                                <th scope="row">{row.feature}</th>
                                <td>{row.basic}</td>
                                <td>{row.pro}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
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

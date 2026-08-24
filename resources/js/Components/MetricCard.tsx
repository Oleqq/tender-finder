import { Icon, type IconName } from './Icon';
import { GlassCard } from './ui';

export function MetricCard({
    icon,
    label,
    value,
    detail,
    accent = false,
}: {
    icon: IconName;
    label: string;
    value: string;
    detail: string;
    accent?: boolean;
}) {
    return (
        <GlassCard className={`metric-card ${accent ? 'metric-card--accent' : ''}`}>
            <span className="metric-card__icon">
                <Icon name={icon} size={18} />
            </span>
            <p>{label}</p>
            <strong>{value}</strong>
            <span>{detail}</span>
        </GlassCard>
    );
}

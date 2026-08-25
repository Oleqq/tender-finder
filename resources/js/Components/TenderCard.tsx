import { Badge, GlassCard } from './ui';
import { Icon } from './Icon';

type TenderCardProps = {
    title: string;
    customer: string;
    price: string;
    deadline: string;
    status?: 'Новый' | 'Срочный' | 'Подходит';
    match: string;
    description?: string | null;
    href?: string;
};

export function TenderCard({
    title,
    customer,
    price,
    deadline,
    status = 'Подходит',
    match,
    description,
    href,
}: TenderCardProps) {
    const tone =
        status === 'Срочный' ? 'warning' : status === 'Новый' ? 'accent' : 'success';

    return (
        <GlassCard as="article" className="tender-card">
            <div className="tender-card__meta">
                <Badge tone={tone}>{status}</Badge>
                <span>
                    <Icon name="spark" size={14} /> {match}
                </span>
            </div>
            <h3>{title}</h3>
            <p>{customer}</p>
            {description ? (
                <p className="tender-card__description">{description}</p>
            ) : null}
            <div className="tender-card__footer">
                <strong>{price}</strong>
                <span>{deadline}</span>
            </div>
            {href ? (
                <a
                    className="tender-card__link"
                    href={href}
                    rel="noreferrer"
                    target="_blank"
                >
                    Открыть извещение
                </a>
            ) : null}
        </GlassCard>
    );
}

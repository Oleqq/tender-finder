import { Badge, GlassCard } from './ui';
import { Icon } from './Icon';

type TenderCardProps = {
    title: string;
    customer: string;
    price: string;
    deadline: string;
    status?: 'Новый' | 'Срочный' | 'Подходит';
    match: string;
};

export function TenderCard({
    title,
    customer,
    price,
    deadline,
    status = 'Подходит',
    match,
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
            <div className="tender-card__footer">
                <strong>{price}</strong>
                <span>{deadline}</span>
            </div>
        </GlassCard>
    );
}

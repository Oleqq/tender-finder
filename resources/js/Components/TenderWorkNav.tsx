import { scopedUrl } from '../lib/workspace';
import { Link, usePage } from '@inertiajs/react';
import '../../css/tender-work.css';
import { Icon, type IconName } from './Icon';
import { type TeamScope } from './WorkspacePicker';
import type { PageProps } from '../types';

const items: Array<{
    href: string;
    label: string;
    description: string;
    icon: IconName;
}> = [
    {
        href: '/tenders',
        label: 'Лента',
        description: 'Новые совпадения',
        icon: 'layers',
    },
    {
        href: '/participation',
        label: 'Участие',
        description: 'Заявки и задачи',
        icon: 'check',
    },
    {
        href: '/participation/analytics',
        label: 'Аналитика',
        description: 'Результаты и воронка',
        icon: 'chart',
    },
    {
        href: '/calendar',
        label: 'Календарь',
        description: 'Сроки и действия',
        icon: 'calendar',
    },
];

export function TenderWorkNav({ active }: { active: string }) {
    const { team } = usePage<PageProps<Partial<TeamScope>>>().props;
    return (
        <nav aria-label="Работа с тендерами" className="work-nav">
            {items.map((item) => (
                <Link
                    key={item.href}
                    href={scopedUrl(item.href, team ?? null)}
                    aria-current={active === item.href ? 'page' : undefined}
                    className={active === item.href ? 'is-active' : ''}
                >
                    <span className="work-nav__icon">
                        <Icon name={item.icon} size={19} />
                    </span>
                    <span className="work-nav__copy">
                        <strong>{item.label}</strong>
                        <small>{item.description}</small>
                    </span>
                </Link>
            ))}
        </nav>
    );
}

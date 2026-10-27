import type { ReactNode } from 'react';
import { Icon, type IconName } from './Icon';
import { TenderWorkNav } from './TenderWorkNav';
import { WorkspacePicker } from './WorkspacePicker';

const tabs = {
    feed: {
        path: '/tenders',
        icon: 'layers',
        eyebrow: 'Поиск закупок',
        title: 'Ваша лента',
        description: 'Совпадения по вашим мониторингам и разбор подходящих закупок.',
    },
    participation: {
        path: '/participation',
        icon: 'check',
        eyebrow: 'Рабочий процесс',
        title: 'Ваши заявки',
        description:
            'Добавляйте закупки из ленты, готовьте документы и фиксируйте результат.',
    },
    analytics: {
        path: '/participation/analytics',
        icon: 'chart',
        eyebrow: 'Результаты участия',
        title: 'Воронка и экономика',
        description:
            'Следите за движением заявок, конверсией и финансовым результатом.',
    },
    calendar: {
        path: '/calendar',
        icon: 'calendar',
        eyebrow: 'Планирование',
        title: 'Сроки под контролем',
        description: 'Подача заявок, личные действия и задачи.',
    },
} satisfies Record<
    string,
    {
        path: string;
        icon: IconName;
        eyebrow: string;
        title: string;
        description: string;
    }
>;

export function TenderWorkspaceHeader({
    tab,
    description,
    status,
}: {
    tab: keyof typeof tabs;
    description?: ReactNode;
    status?: ReactNode;
}) {
    const content = tabs[tab];

    return (
        <div className="workspace-header">
            <TenderWorkNav active={content.path} />
            <WorkspacePicker path={content.path} />
            <section className="work-page-hero page-enter">
                <span className="work-page-hero__icon">
                    <Icon name={content.icon} size={23} />
                </span>
                <div>
                    <p>{content.eyebrow}</p>
                    <h2>{content.title}</h2>
                    <span>{description ?? content.description}</span>
                    {status ? (
                        <div className="work-page-hero__status">{status}</div>
                    ) : null}
                </div>
            </section>
        </div>
    );
}

import { scopedUrl } from '../lib/workspace';
import { Link, usePage } from '@inertiajs/react';
import '../../css/tender-work.css';
import { type TeamScope } from './WorkspacePicker';
import type { PageProps } from '../types';

export function TenderWorkNav({ active }: { active: string }) {
    const { team } = usePage<PageProps<Partial<TeamScope>>>().props;
    return (
        <nav aria-label="Работа с тендерами" className="work-nav">
            {[
                ['/tenders', 'Лента'],
                ['/participation', 'Участие'],
                ['/participation/analytics', 'Аналитика'],
                ['/calendar', 'Календарь'],
            ].map(([href, label]) => (
                <Link
                    key={href}
                    href={href === '/tenders' ? href : scopedUrl(href, team ?? null)}
                    aria-current={active === href ? 'page' : undefined}
                    className={active === href ? 'is-active' : ''}
                >
                    {label}
                </Link>
            ))}
        </nav>
    );
}

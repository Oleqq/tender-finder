import { Link } from '@inertiajs/react';
import '../../css/tender-work.css';

export function TenderWorkNav({ active }: { active: string }) {
    return (
        <nav aria-label="Работа с тендерами" className="work-nav">
            {[
                ['/tenders', 'Лента'],
                ['/participation', 'Участие'],
                ['/calendar', 'Календарь'],
            ].map(([href, label]) => (
                <Link
                    key={href}
                    href={href}
                    aria-current={active === href ? 'page' : undefined}
                    className={active === href ? 'is-active' : ''}
                >
                    {label}
                </Link>
            ))}
        </nav>
    );
}

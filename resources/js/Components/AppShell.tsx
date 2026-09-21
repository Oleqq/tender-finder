import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Icon, type IconName } from './Icon';

type NavigationItem = {
    href: string;
    label: string;
    icon: IconName;
};

export type AppRole = 'subscriber' | 'super_admin';

const subscriberNavigation: NavigationItem[] = [
    { href: '/dashboard', label: 'Обзор', icon: 'home' },
    { href: '/tenders', label: 'Тендеры', icon: 'tenders' },
    { href: '/profile', label: 'Профиль', icon: 'user' },
];

type AppShellProps = {
    children: ReactNode;
    title: string;
    eyebrow?: string;
    activeNav?: string;
    backHref?: string;
    action?: ReactNode;
    navigationVisible?: boolean;
    className?: string;
    /** Kept for specialized pages; primary navigation is intentionally role-neutral. */
    role?: AppRole;
    wide?: boolean;
};

export function AppShell({
    children,
    title,
    eyebrow,
    activeNav,
    backHref,
    action,
    navigationVisible = true,
    className,
    wide = false,
}: AppShellProps) {
    return (
        <main className="mini-app">
            <div className="ambient ambient--one" />
            <div className="ambient ambient--two" />
            <div className={`app-shell ${wide ? 'app-shell--wide' : ''}`}>
                <header className="top-bar">
                    <div className="top-bar__side">
                        {backHref ? (
                            <Link
                                aria-label="Назад"
                                className="icon-button"
                                href={backHref}
                            >
                                <Icon name="arrow-left" size={21} />
                            </Link>
                        ) : (
                            <span aria-hidden="true" className="brand-mark">
                                <Icon name="wave" size={17} />
                            </span>
                        )}
                    </div>
                    <div className="top-bar__title">
                        {eyebrow ? <p>{eyebrow}</p> : null}
                        <h1>{title}</h1>
                    </div>
                    <div className="top-bar__side top-bar__side--end">{action}</div>
                </header>
                <div className={`page-content ${className ?? ''}`}>{children}</div>
                {navigationVisible ? (
                    <BottomNavigation activeNav={activeNav} wide={wide} />
                ) : null}
            </div>
        </main>
    );
}

function BottomNavigation({ activeNav, wide }: { activeNav?: string; wide: boolean }) {
    // Administrative tools are deliberately kept out of the primary user flow.
    // They remain reachable from the profile for a verified owner, without making
    // every subscriber-facing screen look like an operator console.
    const navigation = subscriberNavigation;

    return (
        <nav
            aria-label="Основная навигация"
            className={`bottom-navigation bottom-navigation--${navigation.length} ${wide ? 'bottom-navigation--wide' : ''}`}
        >
            {navigation.map((item) => (
                <Link
                    aria-current={activeNav === item.href ? 'page' : undefined}
                    className={`bottom-navigation__item ${activeNav === item.href ? 'is-active' : ''}`}
                    href={item.href}
                    key={item.href}
                    preserveScroll
                >
                    <Icon name={item.icon} size={21} />
                    <span>{item.label}</span>
                </Link>
            ))}
        </nav>
    );
}

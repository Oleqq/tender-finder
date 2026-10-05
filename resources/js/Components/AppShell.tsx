import { Link, router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Icon, type IconName } from './Icon';

// Remember only actual page transitions, not background refreshes of this page.
let previousAppUrl: string | null = null;
router.on('before', (event) => {
    if (
        event.detail.visit.method === 'get' &&
        event.detail.visit.url.href !== window.location.href
    ) {
        previousAppUrl = window.location.href;
    }
});

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
    backToPrevious?: boolean;
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
    backToPrevious = false,
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
                                onClick={(event) => {
                                    if (
                                        event.ctrlKey ||
                                        event.metaKey ||
                                        event.shiftKey ||
                                        event.altKey
                                    )
                                        return;
                                    if (
                                        previousAppUrl &&
                                        (backToPrevious ||
                                            new URL(previousAppUrl).pathname ===
                                                new URL(backHref, window.location.href)
                                                    .pathname)
                                    ) {
                                        event.preventDefault();
                                        previousAppUrl = null;
                                        window.history.back();
                                    }
                                }}
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
                >
                    <Icon name={item.icon} size={21} />
                    <span>{item.label}</span>
                </Link>
            ))}
        </nav>
    );
}

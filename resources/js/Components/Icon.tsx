import type { SVGProps } from 'react';

export type IconName =
    | 'arrow-left'
    | 'arrow-right'
    | 'bell'
    | 'chart'
    | 'check'
    | 'chevron-right'
    | 'compass'
    | 'filter'
    | 'home'
    | 'layers'
    | 'plus'
    | 'search'
    | 'settings'
    | 'shield'
    | 'spark'
    | 'tenders'
    | 'user'
    | 'wave';

type IconProps = SVGProps<SVGSVGElement> & {
    name: IconName;
    size?: number;
};

export function Icon({ name, size = 20, ...props }: IconProps) {
    const shared = {
        width: size,
        height: size,
        viewBox: '0 0 24 24',
        fill: 'none',
        stroke: 'currentColor',
        strokeWidth: 1.8,
        strokeLinecap: 'round' as const,
        strokeLinejoin: 'round' as const,
        'aria-hidden': true,
        ...props,
    };

    switch (name) {
        case 'arrow-left':
            return (
                <svg {...shared}>
                    <path d="m15 18-6-6 6-6" />
                    <path d="M9 12h11" />
                </svg>
            );
        case 'arrow-right':
            return (
                <svg {...shared}>
                    <path d="m9 18 6-6-6-6" />
                    <path d="M4 12h11" />
                </svg>
            );
        case 'bell':
            return (
                <svg {...shared}>
                    <path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" />
                    <path d="M10 21h4" />
                </svg>
            );
        case 'chart':
            return (
                <svg {...shared}>
                    <path d="M4 19V5" />
                    <path d="M4 19h16" />
                    <path d="m7 15 4-4 3 2 5-6" />
                </svg>
            );
        case 'check':
            return (
                <svg {...shared}>
                    <path d="m5 12 4 4L19 6" />
                </svg>
            );
        case 'chevron-right':
            return (
                <svg {...shared}>
                    <path d="m9 18 6-6-6-6" />
                </svg>
            );
        case 'compass':
            return (
                <svg {...shared}>
                    <circle cx="12" cy="12" r="8" />
                    <path d="m15.5 8.5-2.2 4.8-4.8 2.2 2.2-4.8 4.8-2.2Z" />
                </svg>
            );
        case 'filter':
            return (
                <svg {...shared}>
                    <path d="M4 6h16" />
                    <path d="M7 12h10" />
                    <path d="M10 18h4" />
                </svg>
            );
        case 'home':
            return (
                <svg {...shared}>
                    <path d="m4 11 8-7 8 7v8a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-8Z" />
                    <path d="M9 20v-6h6v6" />
                </svg>
            );
        case 'layers':
            return (
                <svg {...shared}>
                    <path d="m12 3 8 4.5-8 4.5-8-4.5L12 3Z" />
                    <path d="m4 12 8 4.5 8-4.5" />
                    <path d="m4 16.5 8 4.5 8-4.5" />
                </svg>
            );
        case 'plus':
            return (
                <svg {...shared}>
                    <path d="M12 5v14M5 12h14" />
                </svg>
            );
        case 'search':
            return (
                <svg {...shared}>
                    <circle cx="11" cy="11" r="6.5" />
                    <path d="m16 16 4 4" />
                </svg>
            );
        case 'settings':
            return (
                <svg {...shared}>
                    <circle cx="12" cy="12" r="3" />
                    <path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.05.05-2.1 2.1-.05-.05a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.03 1.57v.08h-3v-.08A1.7 1.7 0 0 0 10.7 18.6a1.7 1.7 0 0 0-1.88.34l-.05.05-2.1-2.1.05-.05A1.7 1.7 0 0 0 7.06 15a1.7 1.7 0 0 0-1.57-1.03h-.08v-3h.08A1.7 1.7 0 0 0 7.06 9.94a1.7 1.7 0 0 0-.34-1.88l-.05-.05 2.1-2.1.05.05a1.7 1.7 0 0 0 1.88.34 1.7 1.7 0 0 0 1.03-1.57v-.08h3v.08a1.7 1.7 0 0 0 1.03 1.57 1.7 1.7 0 0 0 1.88-.34l.05-.05 2.1 2.1-.05.05a1.7 1.7 0 0 0-.34 1.88 1.7 1.7 0 0 0 1.57 1.03h.08v3h-.08A1.7 1.7 0 0 0 19.4 15Z" />
                </svg>
            );
        case 'shield':
            return (
                <svg {...shared}>
                    <path d="M12 3 19 6v5c0 4.8-3 8-7 10-4-2-7-5.2-7-10V6l7-3Z" />
                    <path d="m9 12 2 2 4-4" />
                </svg>
            );
        case 'spark':
            return (
                <svg {...shared}>
                    <path d="M12 3 14 10l7 2-7 2-2 7-2-7-7-2 7-2 2-7Z" />
                </svg>
            );
        case 'tenders':
            return (
                <svg {...shared}>
                    <path d="M8 4h8l1 3h3v13H4V7h3l1-3Z" />
                    <path d="M9 11h6M9 15h4" />
                </svg>
            );
        case 'user':
            return (
                <svg {...shared}>
                    <circle cx="12" cy="8" r="3.5" />
                    <path d="M5 21c.7-4 3-6 7-6s6.3 2 7 6" />
                </svg>
            );
        case 'wave':
            return (
                <svg {...shared}>
                    <path d="M3 14c2.1 0 2.1-5 4.2-5s2.1 8 4.2 8 2.1-11 4.2-11 2.1 8 5.4 8" />
                </svg>
            );
    }
}

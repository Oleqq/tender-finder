import { useEffect, useState } from 'react';
import { applySystemTheme, syncThemeColor } from './theme';

type TelegramThemeParams = {
    bg_color?: string;
    secondary_bg_color?: string;
    text_color?: string;
    hint_color?: string;
    link_color?: string;
    button_color?: string;
    button_text_color?: string;
    section_bg_color?: string;
    section_header_text_color?: string;
    subtitle_text_color?: string;
    destructive_text_color?: string;
};

type TelegramWebApp = {
    initData?: string;
    platform?: string;
    colorScheme?: 'light' | 'dark';
    themeParams?: TelegramThemeParams;
    viewportStableHeight?: number;
    safeAreaInset?: { top?: number; right?: number; bottom?: number; left?: number };
    contentSafeAreaInset?: {
        top?: number;
        right?: number;
        bottom?: number;
        left?: number;
    };
    ready: () => void;
    expand: () => void;
    setHeaderColor?: (color: string) => void;
    setBackgroundColor?: (color: string) => void;
    setBottomBarColor?: (color: string) => void;
    onEvent?: (
        eventType: 'themeChanged' | 'viewportChanged',
        callback: () => void,
    ) => void;
    offEvent?: (
        eventType: 'themeChanged' | 'viewportChanged',
        callback: () => void,
    ) => void;
};

declare global {
    interface Window {
        Telegram?: { WebApp?: TelegramWebApp };
    }
}

const setCssVariable = (name: string, value?: string | number): void => {
    if (value !== undefined && value !== '') {
        document.documentElement.style.setProperty(name, String(value));
    } else {
        document.documentElement.style.removeProperty(name);
    }
};

// This selects visual styling only; authentication still verifies initData on the server.
const isTelegramContext = (webApp: TelegramWebApp): boolean =>
    Boolean(webApp.initData) ||
    Boolean(webApp.platform && webApp.platform !== 'unknown');

const syncTelegramChrome = (webApp: TelegramWebApp, background: string): void => {
    try {
        webApp.setHeaderColor?.(background);
    } catch {
        // Clients older than 6.9 accept only a Telegram color key for the header.
        try {
            webApp.setHeaderColor?.('secondary_bg_color');
        } catch {
            // A missing chrome capability must not prevent local theme updates.
        }
    }

    try {
        webApp.setBackgroundColor?.(background);
    } catch {
        // Keep the CSS palette even if the client's chrome API is unavailable.
    }

    try {
        webApp.setBottomBarColor?.(background);
    } catch {
        // The bottom bar is optional on older clients.
    }
};

const applyTelegramTheme = (webApp: TelegramWebApp): void => {
    const theme = webApp.themeParams ?? {};
    const root = document.documentElement;

    root.dataset.telegram = 'true';
    root.dataset.theme = webApp.colorScheme ?? 'light';
    setCssVariable('--tg-bg', theme.bg_color);
    setCssVariable('--tg-secondary-bg', theme.secondary_bg_color);
    setCssVariable('--tg-text', theme.text_color);
    setCssVariable('--tg-hint', theme.hint_color);
    setCssVariable('--tg-link', theme.link_color);
    setCssVariable('--tg-button', theme.button_color);
    setCssVariable('--tg-button-text', theme.button_text_color);
    setCssVariable('--tg-section-bg', theme.section_bg_color);
    setCssVariable('--tg-section-header', theme.section_header_text_color);
    setCssVariable('--tg-subtitle', theme.subtitle_text_color);
    setCssVariable('--tg-destructive', theme.destructive_text_color);
    setCssVariable(
        '--tg-viewport-height',
        webApp.viewportStableHeight ? `${webApp.viewportStableHeight}px` : undefined,
    );

    const safeArea = webApp.contentSafeAreaInset ?? webApp.safeAreaInset;
    setCssVariable('--tg-safe-top', safeArea?.top ? `${safeArea.top}px` : '0px');
    setCssVariable('--tg-safe-right', safeArea?.right ? `${safeArea.right}px` : '0px');
    setCssVariable(
        '--tg-safe-bottom',
        safeArea?.bottom ? `${safeArea.bottom}px` : '0px',
    );
    setCssVariable('--tg-safe-left', safeArea?.left ? `${safeArea.left}px` : '0px');
    syncTelegramChrome(webApp, syncThemeColor());
};

/**
 * Applies optional Telegram chrome and theme values. The visual layer never
 * treats WebApp values or initData as authenticated user information.
 */
export const useTelegramWebApp = (): TelegramWebApp | undefined => {
    const [webApp, setWebApp] = useState(() => window.Telegram?.WebApp);

    useEffect(() => {
        const sdk = document.getElementById('telegram-web-app-sdk');
        const loaded = (): void => setWebApp(window.Telegram?.WebApp);
        sdk?.addEventListener('load', loaded);
        // Covers a load between the initial render and effect subscription.
        loaded();
        return () => sdk?.removeEventListener('load', loaded);
    }, []);

    useEffect(() => {
        const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');
        const sync = (): void => {
            if (webApp && isTelegramContext(webApp)) {
                applyTelegramTheme(webApp);
            } else {
                applySystemTheme(systemTheme.matches);
            }
        };

        sync();
        systemTheme.addEventListener('change', sync);

        try {
            if (webApp && isTelegramContext(webApp)) {
                webApp.ready();
                webApp.expand();
            }
        } catch {
            // A partial SDK must not prevent theme subscriptions.
        }

        try {
            webApp?.onEvent?.('themeChanged', sync);
            webApp?.onEvent?.('viewportChanged', sync);
        } catch {
            // The local palette is already applied if event support is missing.
        }

        return () => {
            systemTheme.removeEventListener('change', sync);
            try {
                webApp?.offEvent?.('themeChanged', sync);
                webApp?.offEvent?.('viewportChanged', sync);
            } catch {
                // Partial SDK implementations may also omit event cleanup.
            }
        };
    }, [webApp]);

    return webApp;
};

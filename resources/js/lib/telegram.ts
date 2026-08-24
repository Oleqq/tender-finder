import { useEffect } from 'react';

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
};

/**
 * Applies optional Telegram chrome and theme values. The visual layer never
 * treats WebApp values or initData as authenticated user information.
 */
export const useTelegramWebApp = (): void => {
    useEffect(() => {
        const webApp = window.Telegram?.WebApp;

        if (!webApp) {
            return;
        }

        const sync = (): void => applyTelegramTheme(webApp);

        try {
            webApp.ready();
            webApp.expand();
            webApp.setHeaderColor?.('secondary_bg_color');
            webApp.setBackgroundColor?.('bg_color');
            sync();
            webApp.onEvent?.('themeChanged', sync);
            webApp.onEvent?.('viewportChanged', sync);
        } catch {
            // A browser extension or an older Telegram client may expose only
            // part of the SDK. The Mini App still works with local CSS defaults.
        }

        return () => {
            webApp.offEvent?.('themeChanged', sync);
            webApp.offEvent?.('viewportChanged', sync);
        };
    }, []);
};

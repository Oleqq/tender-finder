import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { useTelegramWebApp } from '../lib/telegram';
import { TelegramSessionContext } from '../lib/telegramSession';
import type { TelegramSessionState } from '../lib/telegramSession';

export function TelegramSetup({ children }: { children: ReactNode }) {
    useTelegramWebApp();
    const [state, setState] = useState<TelegramSessionState>('checking');

    useEffect(() => {
        let cancelled = false;
        let checking = false;
        setState('checking');

        const authenticate = (initData: string): void => {
            if (checking) return;
            checking = true;
            clearInterval(poll);
            clearTimeout(timeout);

            void window.axios
                .post('/telegram/session', { init_data: initData }, { timeout: 15000 })
                .then(({ data }) => {
                    if (cancelled) return;
                    const identityKey = `${data.user.id}:${data.user.role}`;
                    let synchronizedIdentity: string | null = null;
                    try {
                        synchronizedIdentity = window.sessionStorage.getItem(
                            'tender-finder.telegram-identity',
                        );
                        window.sessionStorage.setItem(
                            'tender-finder.telegram-identity',
                            identityKey,
                        );
                    } catch {
                        // Session storage may be disabled in a Telegram WebView.
                        synchronizedIdentity = identityKey;
                    }

                    if (
                        data.session_refreshed === true ||
                        synchronizedIdentity !== identityKey
                    ) {
                        // Reload to receive the current CSRF token and Inertia auth props.
                        window.location.reload();
                    } else {
                        setState('ready');
                    }
                })
                .catch((error: { response?: { data?: { code?: string } } }) => {
                    if (cancelled) return;
                    setState(
                        error.response?.data?.code === 'telegram_session_expired'
                            ? 'expired'
                            : 'failed',
                    );
                });
        };

        // Some desktop WebViews expose WebApp before initData is populated.
        const check = (): void => {
            const initData = window.Telegram?.WebApp?.initData;
            if (initData) authenticate(initData);
        };
        const poll = window.setInterval(check, 200);
        const timeout = window.setTimeout(() => {
            clearInterval(poll);
            if (!checking && !cancelled) setState('missing');
        }, 3000);
        check();

        return () => {
            cancelled = true;
            clearInterval(poll);
            clearTimeout(timeout);
        };
    }, []);

    return (
        <TelegramSessionContext.Provider value={state}>
            {children}
        </TelegramSessionContext.Provider>
    );
}

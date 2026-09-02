import { useEffect } from 'react';
import { useTelegramWebApp } from '../lib/telegram';

export function TelegramSetup() {
    useTelegramWebApp();

    useEffect(() => {
        const initData = window.Telegram?.WebApp?.initData;

        if (!initData) {
            return;
        }

        void window.axios
            .post('/telegram/session', { init_data: initData })
            .then(({ data }) => {
                const identityKey = `${data.user.id}:${data.user.role}`;
                const synchronizedIdentity = window.sessionStorage.getItem(
                    'tender-finder.telegram-identity',
                );

                if (
                    data.session_refreshed === true ||
                    synchronizedIdentity !== identityKey
                ) {
                    // The Laravel session may have changed, or a Railway role setting
                    // may have promoted/demoted the same Telegram user since the
                    // current GET. Reload once to receive both the current CSRF token
                    // and the current Inertia auth props.
                    window.sessionStorage.setItem(
                        'tender-finder.telegram-identity',
                        identityKey,
                    );
                    window.location.reload();
                }
            })
            .catch(() => {
                // Regular browser sessions and old Telegram clients remain anonymous.
                // The server has already recorded no trust in client-side fields.
            });
    }, []);

    return null;
}

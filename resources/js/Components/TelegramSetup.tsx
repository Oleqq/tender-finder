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
                if (data.session_refreshed === true) {
                    // The Laravel session ID (and therefore the CSRF token) changed
                    // after Telegram identity verification. A complete GET receives
                    // the matching token before a user can submit a protected form.
                    window.location.reload();
                }
            })
            .catch(() => {
                // Browser preview and old Telegram clients remain anonymous.
                // The server has already recorded no trust in client-side fields.
            });
    }, []);

    return null;
}

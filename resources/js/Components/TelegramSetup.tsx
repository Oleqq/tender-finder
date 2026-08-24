import { router } from '@inertiajs/react';
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
            .then(() => router.reload({ only: ['auth'] }))
            .catch(() => {
                // Browser preview and old Telegram clients remain anonymous.
                // The server has already recorded no trust in client-side fields.
            });
    }, []);

    return null;
}

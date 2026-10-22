import '../css/app.css';
import '../css/theme-light.css';
import '../css/theme-dark.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { TelegramSetup } from './Components/TelegramSetup';
import { applySystemTheme } from './lib/theme';

const appName = import.meta.env.VITE_APP_NAME || 'Tender Finder';

applySystemTheme();

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <>
                <TelegramSetup>
                    <App {...props} />
                </TelegramSetup>
            </>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});

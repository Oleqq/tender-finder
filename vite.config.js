import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.tsx',
            refresh: true,
        }),
        react(),
    ],
    server: {
        // Vite listens on every container interface, but the browser must never
        // receive 0.0.0.0 as an asset or HMR endpoint. The dev Compose overlay
        // supplies the loopback host that it publishes to Windows.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: {
            host: process.env.VITE_HMR_HOST ?? 'localhost',
            port: Number(process.env.VITE_HMR_PORT ?? 5173),
        },
    },
});

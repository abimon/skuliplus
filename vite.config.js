import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

const root = dirname(fileURLToPath(import.meta.url));

export default defineConfig({
    resolve: {
        alias: {
            // The published package points at omitted src files; its dist bundle is included.
            '@sautikit/webrtc': resolve(root, 'node_modules/@sautikit/webrtc/dist/index.js'),
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            ssr: 'resources/js/ssr.jsx',
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    esbuild: {
        jsx: 'automatic',
    },
});

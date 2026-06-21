import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import fs from 'fs';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        // The Wayfinder plugin runs `php artisan` which requires PHP + composer
        // vendor files. In environments without PHP we skip the plugin so the
        // dev server can start for frontend-only work. In local full-stack
        // setups `vendor/autoload.php` will exist and enable the plugin.
        (fs.existsSync('vendor/autoload.php')
            ? wayfinder({ formVariants: true })
            : { name: 'skip-wayfinder' }),
    ],
});

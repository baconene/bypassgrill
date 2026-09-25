import path from 'node:path';
import tailwind from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { createServer } from 'vite';
const root = process.cwd();
const file = (name) => path.join(root, 'scripts/demo', name);
const server = await createServer({
    configFile: false,
    root: file(''),
    publicDir: path.join(root, 'public'),
    plugins: [vue(), tailwind()],
    resolve: {
        alias: {
            '@inertiajs/vue3': file('inertia.ts'),
            '@/utils/api': file('api.ts'),
            '@/utils/offlineSync': file('offline.ts'),
            '@/utils/offlineQueue': file('offline.ts'),
            '@/utils/printReceipt': file('print.ts'),
            '@': path.join(root, 'resources/js'),
        },
    },
    server: {
        host: '127.0.0.1',
        port: 4181,
        strictPort: true,
        fs: { allow: [root] },
    },
});
await server.listen();

import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/kartu-ujian.js'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    build: {
        rollupOptions: {
            output: {
                // Worker PDF (pdf.worker.min.mjs) dikeluarkan ber-akhiran .js (13 Nov 2026): nginx VPS mengirim .mjs
                // sebagai application/octet-stream, dan browser MENOLAK modul ber-MIME itu → pratinjau PDF gagal
                // ("Setting up fake worker failed"). .js selalu dikirim sebagai JavaScript di server mana pun.
                assetFileNames: (aset) => ((aset.names?.[0] ?? aset.name ?? '').endsWith('.mjs')
                    ? 'assets/[name]-[hash].js'
                    : 'assets/[name]-[hash][extname]'),
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});

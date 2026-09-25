import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/whatsapp-flow-builder.js',
                // Proposal documents only -- scoped under .cp-doc, loaded by
                // the proposal pages and nowhere else.
                'resources/css/proposal.css',
            ],
            refresh: true,
        }),
    ],
});

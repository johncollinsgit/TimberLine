import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(() => ({
  plugins: [
    laravel({
      input: ['resources/css/app.css', 'resources/js/app.js'],
      refresh: true,
    }),
    tailwindcss(),
  ],

  // Keep Vite dev server predictable for Laravel + Safari
  server: {
    host: 'localhost',
    port: 5173,
    strictPort: true,
    origin: 'http://localhost:5173',
    cors: true,
    hmr: {
      host: 'localhost',
      port: 5173,
    },
    watch: {
      // don’t let compiled blade views trigger endless rebuild loops
      ignored: [
        '**/storage/**',
        '**/bootstrap/cache/**',
        '**/public/build/**',
      ],
    },
  },

  // Make sure prod build matches what Laravel expects
  build: {
    manifest: 'manifest.json',
    outDir: 'public/build',
    emptyOutDir: true,
  },
}));

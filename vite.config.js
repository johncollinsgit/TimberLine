import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(() => ({
  plugins: [
    react(),
    laravel({
      input: [
        'resources/css/app.css',
        'resources/js/app.js',
        'resources/js/admin/master-data-grid.tsx',
        'resources/js/marketing/customers-grid.tsx',
        'resources/js/shopify/dashboard.tsx',
      ],
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

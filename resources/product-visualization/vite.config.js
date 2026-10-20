import {defineConfig} from 'vite';
import {fileURLToPath} from 'node:url';
export default defineConfig({
  base: './',
  build: {
    outDir: fileURLToPath(new URL('../../public/product-visualization',import.meta.url)),
    emptyOutDir: true,
    lib: {entry: 'src/viewer.js', formats: ['es'], fileName: ()=>'viewer.js', cssFileName:'viewer'},
    rollupOptions: {output: {assetFileNames:'[name][extname]'}},
    target: 'es2022',
  },
});

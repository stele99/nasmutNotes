import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
  base: '/build/',
  plugins: [tailwindcss()],
  root: '.',
  publicDir: false,
  build: {
    manifest: true,
    outDir: 'public/build',
    emptyOutDir: true,
    rollupOptions: {
      input: {
        app: 'resources/js/app.js',
        editor: 'resources/js/editor/index.js',
      },
    },
  },
  server: {
    strictPort: true,
    port: 5173,
    origin: 'http://localhost:5173',
  },
});

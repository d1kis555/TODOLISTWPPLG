import { defineConfig } from 'vite'

export default defineConfig({
  publicDir: false,
  build: {
    outDir: 'assets/dist',
    emptyOutDir: true,
    target: 'es2022',
    cssCodeSplit: false,
    reportCompressedSize: true,
    lib: {
      entry: 'assets/src/main.js',
      name: 'NotionTodo',
      formats: ['iife'],
      fileName: () => 'app.js',
      cssFileName: 'app',
    },
  },
})

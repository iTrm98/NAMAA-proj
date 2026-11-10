import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  // Always 5173: the API only allows this origin (CORS). If the port is busy,
  // fail with an error instead of silently moving to 5174.
  server: { port: 5173, strictPort: true },
  preview: { port: 5173, strictPort: true },
  // maplibre-gl starts its worker as a module worker (lib/maplibreWorker.ts).
  worker: { format: 'es' },
  build: {
    // maplibre-gl alone is about 1.1 MB; it is loaded lazily on admin pages only.
    chunkSizeWarningLimit: 1200,
  },
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
})

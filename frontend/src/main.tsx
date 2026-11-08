import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router'
import '@/i18n'
import '@/lib/theme'
import './index.css'
import { TooltipProvider } from '@/components/ui/tooltip'
import { ApiError } from '@/lib/api'
import App from './App.tsx'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 10_000,
      // Do not retry auth or not-found errors; retry network hiccups once.
      retry: (count, error) => !(error instanceof ApiError && error.status < 500) && count < 1,
    },
  },
})

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <TooltipProvider>
          <App />
        </TooltipProvider>
      </BrowserRouter>
    </QueryClientProvider>
  </StrictMode>,
)

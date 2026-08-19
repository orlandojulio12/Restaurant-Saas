import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import './index.css'
import App from './App'
import { ProveedorSesion } from './features/auth/SesionContext'

const cliente = new QueryClient({
  defaultOptions: {
    queries: {
      // En sala se cambia de pantalla constantemente; refrescar en cada vuelta
      // hace parpadear datos que no han cambiado.
      refetchOnWindowFocus: false,
      staleTime: 10_000,
      retry: 1,
    },
  },
})

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={cliente}>
      <ProveedorSesion>
        <App />
      </ProveedorSesion>
    </QueryClientProvider>
  </StrictMode>,
)

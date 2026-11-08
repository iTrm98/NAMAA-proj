import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes } from 'react-router'
import { RequireRole } from '@/components/RequireRole'
import { homeFor, useAuth } from '@/lib/auth'
import { LoginPage } from '@/pages/LoginPage'
import { PlaceholderPage } from '@/pages/PlaceholderPage'

// Admin pages load on demand (MapLibre and Recharts are large).
const AdminLayout = lazy(() => import('@/pages/admin/AdminLayout'))
const ParkMap = lazy(() => import('@/pages/admin/ParkMap'))
const ParkPage = lazy(() => import('@/pages/admin/ParkPage'))

function Loading() {
  return <div className="h-svh" aria-busy="true" />
}

function Home() {
  const { token, user } = useAuth()
  return <Navigate to={token && user ? homeFor(user.role) : '/login'} replace />
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route
        path="/admin"
        element={
          <RequireRole role="admin">
            <Suspense fallback={<Loading />}>
              <AdminLayout />
            </Suspense>
          </RequireRole>
        }
      >
        <Route index element={<Suspense fallback={null}><ParkMap /></Suspense>} />
        <Route path="parks/:id" element={<Suspense fallback={null}><ParkPage /></Suspense>} />
        <Route path="*" element={<Navigate to="/admin" replace />} />
      </Route>
      <Route
        path="/worker/*"
        element={
          <RequireRole role="worker">
            <PlaceholderPage titleKey="placeholder.workerTitle" bodyKey="placeholder.workerBody" />
          </RequireRole>
        }
      />
      <Route
        path="/municipality/*"
        element={
          <RequireRole role="municipality">
            <PlaceholderPage titleKey="placeholder.municipalityTitle" bodyKey="placeholder.municipalityBody" />
          </RequireRole>
        }
      />
      <Route path="*" element={<Home />} />
    </Routes>
  )
}

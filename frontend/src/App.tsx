import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes } from 'react-router'
import { RouteTitle } from '@/hooks/useDocumentTitle'
import { RealtimeBridge } from '@/components/RealtimeBridge'
import { RequireRole } from '@/components/RequireRole'
import { Toast } from '@/components/ds'
import { homeFor, useAuth } from '@/lib/auth'
import { LoginPage } from '@/pages/LoginPage'

// Admin pages load on demand (MapLibre and Recharts are large).
const AdminLayout = lazy(() => import('@/pages/admin/AdminLayout'))
const ParkMap = lazy(() => import('@/pages/admin/ParkMap'))
const AdminOverviewPage = lazy(() => import('@/pages/admin/OverviewPage'))
const ParksListPage = lazy(() => import('@/pages/ParksListPage'))
const CompaniesPage = lazy(() => import('@/pages/municipality/CompaniesPage'))
const ParkPage = lazy(() => import('@/pages/admin/ParkPage'))
const TasksPage = lazy(() => import('@/pages/admin/TasksPage'))
const DemoPage = lazy(() => import('@/pages/admin/DemoPage'))
const HardwarePage = lazy(() => import('@/pages/admin/HardwarePage'))
const ImpactPage = lazy(() => import('@/pages/admin/ImpactPage'))
const MunicipalityLayout = lazy(() => import('@/pages/municipality/MunicipalityLayout'))
const OverviewPage = lazy(() => import('@/pages/municipality/OverviewPage'))
const WorkerLayout = lazy(() => import('@/pages/worker/WorkerLayout'))
const MyTasksPage = lazy(() => import('@/pages/worker/MyTasksPage'))
const TaskDetailPage = lazy(() => import('@/pages/worker/TaskDetailPage'))

function Loading() {
  return <div className="h-svh" aria-busy="true" />
}

function Home() {
  const { token, user } = useAuth()
  return <Navigate to={token && user ? homeFor(user.role) : '/login'} replace />
}

export default function App() {
  return (
    <>
      <RouteTitle />
      <RealtimeBridge />
      <Toast />
      <AppRoutes />
    </>
  )
}

function AppRoutes() {
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
        <Route index element={<Suspense fallback={null}><AdminOverviewPage /></Suspense>} />
        <Route path="map" element={<Suspense fallback={null}><ParkMap /></Suspense>} />
        <Route path="parks" element={<Suspense fallback={null}><ParksListPage /></Suspense>} />
        <Route path="parks/:id" element={<Suspense fallback={null}><ParkPage /></Suspense>} />
        <Route path="tasks" element={<Suspense fallback={null}><TasksPage /></Suspense>} />
        <Route path="demo" element={<Suspense fallback={null}><DemoPage /></Suspense>} />
        <Route path="hardware" element={<Suspense fallback={null}><HardwarePage /></Suspense>} />
        <Route path="impact" element={<Suspense fallback={null}><ImpactPage /></Suspense>} />
        <Route path="*" element={<Navigate to="/admin" replace />} />
      </Route>
      <Route
        path="/worker"
        element={
          <RequireRole role="worker">
            <Suspense fallback={<Loading />}>
              <WorkerLayout />
            </Suspense>
          </RequireRole>
        }
      >
        <Route index element={<Suspense fallback={null}><MyTasksPage /></Suspense>} />
        <Route path="tasks/:id" element={<Suspense fallback={null}><TaskDetailPage /></Suspense>} />
        <Route path="*" element={<Navigate to="/worker" replace />} />
      </Route>
      <Route
        path="/municipality"
        element={
          <RequireRole role="municipality">
            <Suspense fallback={<Loading />}>
              <MunicipalityLayout />
            </Suspense>
          </RequireRole>
        }
      >
        <Route index element={<Suspense fallback={null}><OverviewPage /></Suspense>} />
        <Route path="companies" element={<Suspense fallback={null}><CompaniesPage /></Suspense>} />
        <Route path="parks" element={<Suspense fallback={null}><ParksListPage /></Suspense>} />
        <Route path="parks/:id" element={<Suspense fallback={null}><ParkPage /></Suspense>} />
        <Route path="*" element={<Navigate to="/municipality" replace />} />
      </Route>
      <Route path="*" element={<Home />} />
    </Routes>
  )
}

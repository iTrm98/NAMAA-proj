import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router'
import { homeFor, useAuth } from '@/lib/auth'
import type { Role } from '@/types'

/** No token -> /login. A user on another role's route -> their own home. */
export function RequireRole({ role, children }: { role: Role; children: ReactNode }) {
  const { token, user } = useAuth()
  const location = useLocation()

  if (!token || !user) return <Navigate to="/login" replace state={{ from: location.pathname }} />
  if (user.role !== role) return <Navigate to={homeFor(user.role)} replace />

  return children
}

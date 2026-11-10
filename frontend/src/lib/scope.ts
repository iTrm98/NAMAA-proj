import { createContext, useContext } from 'react'

/**
 * Which API the shared park pages read: the admin's company ('admin') or every
 * company, read-only ('municipality'). The municipality layout provides
 * 'municipality'; everything else defaults to 'admin'. It is both the URL
 * prefix (/api/{scope}/...) and the first part of the query keys.
 */
export type ApiScope = 'admin' | 'municipality'

export const ScopeContext = createContext<ApiScope>('admin')

export function useScope(): ApiScope {
  return useContext(ScopeContext)
}

/** Read-only pages: no pump switch, no task creation, no threshold edits. */
export function useReadOnly(): boolean {
  return useScope() === 'municipality'
}

/** Base route of the scope's pages (/admin or /municipality). */
export function useBasePath(): string {
  return `/${useScope()}`
}

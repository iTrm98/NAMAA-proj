import { useSyncExternalStore } from 'react'
import type { Role, User } from '@/types'

/**
 * Auth store: the Bearer token and the user, kept in localStorage.
 * localStorage is per browser profile, so a normal window and an incognito
 * window can be signed in as different users at the same time.
 */
const TOKEN_KEY = 'namaa.token'
const USER_KEY = 'namaa.user'

interface AuthState {
  token: string | null
  user: User | null
}

const listeners = new Set<() => void>()

function read(): AuthState {
  try {
    const token = localStorage.getItem(TOKEN_KEY)
    const raw = localStorage.getItem(USER_KEY)
    return { token, user: raw ? (JSON.parse(raw) as User) : null }
  } catch {
    return { token: null, user: null }
  }
}

let state: AuthState = read()

function emit() {
  for (const listener of listeners) listener()
}

export const auth = {
  get: () => state,
  token: () => state.token,

  signIn(token: string, user: User) {
    try {
      localStorage.setItem(TOKEN_KEY, token)
      localStorage.setItem(USER_KEY, JSON.stringify(user))
    } catch {
      // storage blocked: keep the session in memory only
    }
    state = { token, user }
    emit()
  },

  setUser(user: User) {
    try {
      localStorage.setItem(USER_KEY, JSON.stringify(user))
    } catch {
      // ignore
    }
    state = { ...state, user }
    emit()
  },

  signOut() {
    try {
      localStorage.removeItem(TOKEN_KEY)
      localStorage.removeItem(USER_KEY)
    } catch {
      // ignore
    }
    state = { token: null, user: null }
    emit()
  },

  subscribe(listener: () => void) {
    listeners.add(listener)
    return () => listeners.delete(listener)
  },
}

// Another tab signed in or out (same profile): follow it.
window.addEventListener('storage', (event) => {
  if (event.key === TOKEN_KEY || event.key === USER_KEY) {
    state = read()
    emit()
  }
})

export function useAuth(): AuthState {
  return useSyncExternalStore(auth.subscribe, auth.get)
}

export function homeFor(role: Role): string {
  return { admin: '/admin', worker: '/worker', municipality: '/municipality' }[role]
}

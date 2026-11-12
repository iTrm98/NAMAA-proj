import { useSyncExternalStore } from 'react'

/** Dark by default, light toggle; remembered in localStorage (Tailwind `dark` class). */
export type Theme = 'dark' | 'light'

const KEY = 'namaa.theme'
const listeners = new Set<() => void>()

/** Dark theme only for now: the light theme is hidden (no toggle in the UI), the code stays. */
const FORCE_DARK = true

function read(): Theme {
  if (FORCE_DARK) return 'dark'
  try {
    return localStorage.getItem(KEY) === 'light' ? 'light' : 'dark'
  } catch {
    return 'dark'
  }
}

let current: Theme = read()
document.documentElement.classList.toggle('dark', current === 'dark')

export function setTheme(theme: Theme) {
  current = theme
  try {
    localStorage.setItem(KEY, theme)
  } catch {
    // ignore
  }
  document.documentElement.classList.toggle('dark', theme === 'dark')
  for (const listener of listeners) listener()
}

export function useTheme(): Theme {
  return useSyncExternalStore(
    (listener) => {
      listeners.add(listener)
      return () => listeners.delete(listener)
    },
    () => current,
  )
}

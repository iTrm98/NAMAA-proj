import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { useSyncExternalStore } from 'react'
import { BASE_URL } from '@/lib/api'
import type { User } from '@/types'

/**
 * Live updates over Reverb (VITE_REVERB_*). One connection per signed-in user,
 * private channels authorised with the Bearer token at /api/broadcasting/auth:
 *   admin -> company.{id}, worker -> user.{id}, municipality -> municipality.
 * Components listen through onLive(); useLiveStatus() drives the header dot.
 */
export const LIVE_EVENTS = [
  'reading.received',
  'park.status_changed',
  'alert.raised',
  'alert.resolved',
  'alert.updated',
  'pump.changed',
  'task.created',
  'task.updated',
  'task.escalated',
] as const

export type LiveEvent = (typeof LIVE_EVENTS)[number]
export type LiveStatus = 'off' | 'connecting' | 'live' | 'reconnecting'
type Listener = (event: LiveEvent, payload: Record<string, unknown>) => void

declare global {
  interface Window {
    Pusher: typeof Pusher
  }
}
window.Pusher = Pusher

let echo: Echo<'reverb'> | null = null
let connectedFor: string | null = null
let status: LiveStatus = 'off'
const statusListeners = new Set<() => void>()
const eventListeners = new Set<Listener>()

function setStatus(next: LiveStatus) {
  if (next === status) return
  status = next
  for (const listener of statusListeners) listener()
}

function channelFor(user: User): string | null {
  if (user.role === 'admin' && user.company_id) return `company.${user.company_id}`
  if (user.role === 'worker') return `user.${user.id}`
  if (user.role === 'municipality') return 'municipality'
  return null
}

export function connectEcho(user: User, token: string) {
  const key = `${user.id}:${token}`
  if (echo && connectedFor === key) return
  disconnectEcho()

  const appKey = import.meta.env.VITE_REVERB_APP_KEY
  const channel = channelFor(user)
  if (!appKey || !channel) return

  const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'http'
  const port = Number(import.meta.env.VITE_REVERB_PORT ?? 8080)

  echo = new Echo({
    broadcaster: 'reverb',
    key: appKey,
    wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${BASE_URL}/api/broadcasting/auth`,
    auth: { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } },
  })
  connectedFor = key
  setStatus('connecting')

  const pusher = (echo.connector as unknown as { pusher: Pusher }).pusher
  pusher.connection.bind('state_change', ({ current }: { current: string }) => {
    setStatus(current === 'connected' ? 'live' : current === 'connecting' ? (status === 'live' ? 'reconnecting' : 'connecting') : 'reconnecting')
  })

  const subscription = echo.private(channel)
  for (const name of LIVE_EVENTS) {
    subscription.listen(`.${name}`, (payload: Record<string, unknown>) => {
      for (const listener of eventListeners) listener(name, payload)
    })
  }
}

export function disconnectEcho() {
  echo?.disconnect()
  echo = null
  connectedFor = null
  setStatus('off')
}

export function onLive(listener: Listener): () => void {
  eventListeners.add(listener)
  return () => eventListeners.delete(listener)
}

export function useLiveStatus(): LiveStatus {
  return useSyncExternalStore(
    (listener) => {
      statusListeners.add(listener)
      return () => statusListeners.delete(listener)
    },
    () => status,
  )
}

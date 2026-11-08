import { auth } from '@/lib/auth'
import type { ValidationErrorBody } from '@/types'

/**
 * The one API client. Base URL from VITE_API_URL, JSON in and out, Bearer token
 * from the auth store. A 401 signs out and goes to /login.
 */
const BASE_URL = (import.meta.env.VITE_API_URL ?? 'http://localhost:8000').replace(/\/+$/, '')

export class ApiError extends Error {
  readonly status: number
  readonly body: ValidationErrorBody | null

  constructor(status: number, body: ValidationErrorBody | null) {
    super(body?.message ?? `HTTP ${status}`)
    this.status = status
    this.body = body
  }
}

type Method = 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'

export async function api<T>(path: string, options: { method?: Method; body?: unknown; signal?: AbortSignal } = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  const token = auth.token()
  if (token) headers.Authorization = `Bearer ${token}`
  if (options.body !== undefined) headers['Content-Type'] = 'application/json'

  const response = await fetch(`${BASE_URL}/api${path}`, {
    method: options.method ?? 'GET',
    headers,
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    signal: options.signal,
  })

  if (response.status === 401 && token) {
    auth.signOut()
    if (window.location.pathname !== '/login') window.location.assign('/login')
  }

  if (response.status === 204) return undefined as T

  const text = await response.text()
  const data = text ? (JSON.parse(text) as unknown) : null

  if (!response.ok) throw new ApiError(response.status, data as ValidationErrorBody | null)

  return data as T
}

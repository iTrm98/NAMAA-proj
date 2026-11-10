import { auth } from '@/lib/auth'
import type { ValidationErrorBody } from '@/types'

/**
 * The one API client. Base URL from VITE_API_URL, JSON in and out (FormData for
 * uploads), Bearer token from the auth store. A 401 signs out and goes to /login.
 */
export const BASE_URL = (import.meta.env.VITE_API_URL ?? 'http://localhost:8000').replace(/\/+$/, '')

export class ApiError extends Error {
  readonly status: number
  readonly body: ValidationErrorBody | null

  constructor(status: number, body: ValidationErrorBody | null) {
    super(body?.message ?? `HTTP ${status}`)
    this.status = status
    this.body = body
  }
}

/**
 * A request cancelled on purpose (TanStack Query cancels queries when a page
 * unmounts; components abort their own fetches). Not an error: no toast, no log.
 */
export function isAbortError(error: unknown): boolean {
  return (error instanceof DOMException || error instanceof Error) && error.name === 'AbortError'
}

type Method = 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'

interface Options {
  method?: Method
  body?: unknown
  signal?: AbortSignal
}

async function request(path: string, options: Options): Promise<Response> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  const token = auth.token()
  if (token) headers.Authorization = `Bearer ${token}`

  let body: BodyInit | undefined
  if (options.body instanceof FormData) {
    body = options.body // the browser sets the multipart boundary
  } else if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(options.body)
  }

  const response = await fetch(`${BASE_URL}/api${path}`, {
    method: options.method ?? 'GET',
    headers,
    body,
    signal: options.signal,
  })

  if (response.status === 401 && token) {
    auth.signOut()
    if (window.location.pathname !== '/login') window.location.assign('/login')
  }

  return response
}

export async function api<T>(path: string, options: Options = {}): Promise<T> {
  const response = await request(path, options)

  if (response.status === 204) return undefined as T

  const text = await response.text()
  const data = text ? (JSON.parse(text) as unknown) : null

  if (!response.ok) throw new ApiError(response.status, data as ValidationErrorBody | null)

  return data as T
}

/** A protected file (task photos are never public URLs). */
export async function apiBlob(path: string, signal?: AbortSignal): Promise<Blob> {
  const response = await request(path, { signal })
  if (!response.ok) throw new ApiError(response.status, null)
  return response.blob()
}

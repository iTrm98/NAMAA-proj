import type { MultiPolygon, Polygon } from 'geojson'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { POLL_MS } from '@/hooks/useParks'
import { api } from '@/lib/api'
import { useScope } from '@/lib/scope'
import type {
  Alert,
  Paginated,
  ParkDetail,
  PumpState,
  ScenarioName,
  Task,
  TaskPriority,
  TaskStatus,
  Worker,
  WorkerTaskDetail,
  DemoStory,
} from '@/types'

/** Data hooks for alerts, tasks, workers, pump and demo (admin and worker). */

// ---- sorting (same rules everywhere) ----

const PRIORITY_RANK = { urgent: 0, high: 1, normal: 2, low: 3 } as const

/** Urgent first, then high, normal, low; oldest first within a priority (same as the server). */
export function sortAlerts<T extends Pick<Alert, 'priority' | 'created_at'>>(alerts: T[]): T[] {
  return [...alerts].sort((a, b) => PRIORITY_RANK[a.priority] - PRIORITY_RANK[b.priority] || a.created_at.localeCompare(b.created_at))
}

/** Urgent first, then high, normal, low; oldest first within a priority. */
export function sortTasks<T extends Pick<Task, 'priority' | 'created_at'>>(tasks: T[]): T[] {
  return [...tasks].sort((a, b) => PRIORITY_RANK[a.priority] - PRIORITY_RANK[b.priority] || a.created_at.localeCompare(b.created_at))
}

export const isActive = (status: TaskStatus) => status === 'open' || status === 'in_progress'

// ---- admin ----

export function useOpenAlerts() {
  const scope = useScope()
  return useQuery({
    queryKey: [scope, 'alerts', 'open'],
    queryFn: ({ signal }) => api<Paginated<Alert>>(`/${scope}/alerts?status=open&per_page=100`, { signal }).then((r) => sortAlerts(r.data)),
    refetchInterval: POLL_MS,
  })
}

export function useAdminTasks(filters: { status: string; parkId: string }) {
  const params = new URLSearchParams({ sort: 'priority', per_page: '100' })
  if (filters.status !== 'all') params.set('status', filters.status)
  if (filters.parkId !== 'all') params.set('park_id', filters.parkId)

  return useQuery({
    queryKey: ['admin', 'tasks', filters],
    queryFn: ({ signal }) => api<Paginated<Task>>(`/admin/tasks?${params}`, { signal }),
    refetchInterval: POLL_MS,
    placeholderData: keepPreviousData,
  })
}

export type TaskBoard = Record<'todo' | 'in_progress' | 'checking' | 'closed', Task[]>

/** The kanban: the four columns in one request (Sprint 6, B4), with the full column sizes. */
export function useTaskBoard(parkId: string) {
  const params = new URLSearchParams({ grouped: '1', per_column: '50' })
  if (parkId !== 'all') params.set('park_id', parkId)
  return useQuery({
    queryKey: ['admin', 'tasks', 'board', parkId],
    queryFn: ({ signal }) => api<{ data: TaskBoard; counts: Record<keyof TaskBoard, number> }>(`/admin/tasks?${params}`, { signal }),
    refetchInterval: POLL_MS,
    placeholderData: keepPreviousData,
  })
}

export function useWorkers(enabled = true) {
  return useQuery({
    queryKey: ['admin', 'workers'],
    queryFn: ({ signal }) => api<{ data: Worker[] }>('/admin/workers', { signal }).then((r) => r.data),
    enabled,
  })
}

/** After any task change: tasks, alerts (assignee), workers (counts), parks (open tasks). */
function useInvalidateOps() {
  const queryClient = useQueryClient()
  return () => {
    for (const key of [['admin', 'tasks'], ['admin', 'alerts'], ['admin', 'workers'], ['admin', 'parks']]) {
      void queryClient.invalidateQueries({ queryKey: key })
    }
  }
}

export interface NewTask {
  alert_id?: number
  park_id?: number
  assigned_to: number | null
  title: string
  /** A system title (taskTitle.dry...): the server then stores it for both languages. */
  title_key?: string
  note: string | null
  priority: TaskPriority
}

export function useCreateTask() {
  const invalidate = useInvalidateOps()
  return useMutation({
    mutationFn: (body: NewTask) => api<{ data: Task }>('/admin/tasks', { method: 'POST', body }).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useUpdateTask() {
  const invalidate = useInvalidateOps()
  return useMutation({
    mutationFn: ({ id, ...body }: { id: number; assigned_to?: number | null; priority?: TaskPriority; status?: 'cancelled' }) =>
      api<{ data: Task }>(`/admin/tasks/${id}`, { method: 'PATCH', body }).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useSwitchPump(parkId: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (on: boolean) => api<{ data: PumpState }>(`/admin/parks/${parkId}/pump`, { method: 'POST', body: { on } }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'parks'] }),
  })
}

export function useUpdatePark(parkId: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (body: { auto_irrigation?: boolean }) =>
      api<{ data: ParkDetail }>(`/admin/parks/${parkId}`, { method: 'PATCH', body }).then((r) => r.data),
    onSuccess: (park) => {
      queryClient.setQueryData(['admin', 'parks', parkId], park)
      void queryClient.invalidateQueries({ queryKey: ['admin', 'parks'] })
      void queryClient.invalidateQueries({ queryKey: ['admin', 'alerts'] })
    },
  })
}

/** PUT /api/admin/parks/{id}/outline (Sprint 3.1): the editor's outline and its source. */
export interface OutlineBody {
  boundary?: Polygon
  green_zone?: MultiPolygon | null
  imagery: 'esri' | 'maptiler'
  checked: boolean
}

export type OutlineWarning = 'green_zone_outside_boundary' | 'green_zone_larger_than_boundary'

export function useSaveOutline(parkId: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (body: OutlineBody) =>
      api<{ data: ParkDetail; warnings?: OutlineWarning[] }>(`/admin/parks/${parkId}/outline`, { method: 'PUT', body }).then((r) => ({ park: r.data, warnings: r.warnings ?? [] })),
    onSuccess: ({ park }) => {
      queryClient.setQueryData(['admin', 'parks', parkId], park)
      void queryClient.invalidateQueries({ queryKey: ['admin', 'parks'] })
      void queryClient.invalidateQueries({ queryKey: ['admin', 'impact'] })
    },
  })
}

// ---- demo ----

export function useDemoStatus() {
  return useQuery({
    queryKey: ['demo', 'status'],
    queryFn: ({ signal }) => api<{ demo_mode: boolean }>('/demo/status', { signal }).then((r) => r.demo_mode),
    staleTime: 5 * 60_000,
    retry: false,
  })
}

export function useDemoStory(enabled: boolean) {
  return useQuery({
    queryKey: ['admin', 'demo', 'story'],
    queryFn: ({ signal }) => api<{ data: DemoStory }>('/admin/demo/story', { signal }).then((r) => r.data),
    enabled,
    refetchInterval: 30_000,
  })
}

export function useRunScenario() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ name, full }: { name: ScenarioName; full?: boolean }) =>
      api<{ data: Record<string, unknown> }>('/admin/demo/scenario', { method: 'POST', body: full ? { name, full: true } : { name } }).then((r) => r.data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin'] }),
  })
}

// ---- worker ----

export function useMyTasks() {
  return useQuery({
    queryKey: ['worker', 'tasks'],
    queryFn: ({ signal }) => api<{ data: Task[] }>('/worker/tasks', { signal }).then((r) => r.data),
    refetchInterval: POLL_MS,
  })
}

export function useMyTask(id: number) {
  return useQuery({
    queryKey: ['worker', 'tasks', id],
    queryFn: ({ signal }) => api<{ data: WorkerTaskDetail }>(`/worker/tasks/${id}`, { signal }).then((r) => r.data),
    refetchInterval: POLL_MS,
    enabled: Number.isFinite(id),
  })
}

export function useWorkerAction(id: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (action: { kind: 'start' } | { kind: 'pump'; on: boolean } | { kind: 'done'; note: string; photo: File | null }) => {
      if (action.kind === 'start') return api<{ data: WorkerTaskDetail }>(`/worker/tasks/${id}/start`, { method: 'POST' })
      if (action.kind === 'pump') return api<{ data: WorkerTaskDetail }>(`/worker/tasks/${id}/pump`, { method: 'POST', body: { on: action.on } })
      const form = new FormData()
      if (action.note.trim()) form.append('note', action.note.trim())
      if (action.photo) form.append('photo', action.photo)
      return api<{ data: WorkerTaskDetail }>(`/worker/tasks/${id}/done`, { method: 'POST', body: form })
    },
    onSuccess: ({ data }) => {
      queryClient.setQueryData(['worker', 'tasks', id], data)
      void queryClient.invalidateQueries({ queryKey: ['worker', 'tasks'], exact: true })
    },
  })
}

/** GET /admin/system/status: the scheduler heartbeat; polled every 15 s so the banner clears by itself. */
export function useSystemStatus() {
  return useQuery({
    queryKey: ['admin', 'system', 'status'],
    queryFn: ({ signal }) =>
      api<{ data: { scheduler: { heartbeat_at: string | null; age_s: number | null; stalled: boolean } } }>('/admin/system/status', { signal }).then((r) => r.data),
    refetchInterval: 15_000,
    retry: false,
  })
}

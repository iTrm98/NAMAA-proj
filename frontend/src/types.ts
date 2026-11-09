// API types. They mirror the API contract (v2); change the contract first.

export type Role = 'admin' | 'worker' | 'municipality'
export type Source = 'real' | 'simulated'
export type ParkStatus = 'ok' | 'dry' | 'over' | 'offline'
export type Locale = 'ar' | 'en'

export interface User {
  id: number
  name: string
  email: string
  role: Role
  company_id: number | null
  company: { id: number; name: string } | null
  locale: Locale
}

export interface LoginResponse {
  token: string
  user: User
}

export interface GeoJsonPolygon {
  type: 'Polygon'
  coordinates: [number, number][][] // [lng, lat]
}

export interface LatestValues {
  node_id: number
  moisture_pct: number | null
  flow: number | null
  temperature: number | null
  humidity: number | null
  pump_on: boolean | null
  battery_pct: number | null
  at: string
  source: Source
}

export interface Park {
  id: number
  name_ar: string
  name_en: string
  district: string | null
  park_type: 'public_park' | 'median' | 'mosque_garden' | 'other'
  area_m2: number | null
  lat: number
  lng: number
  polygon: GeoJsonPolygon
  status: ParkStatus
  status_changed_at: string | null
  last_reading_at: string | null
  source: Source
  moisture_min: number
  moisture_max: number
  has_pump: boolean
  pump_target: boolean | null
  auto_irrigation: boolean
  open_alerts_count: number
  latest: LatestValues | null
  company?: { id: number; name: string }
}

export interface ParkNode {
  id: number
  name: string
  type: 'sensor' | 'camera'
  last_seen_at: string | null
  online: boolean
  source: Source
  has_pump: boolean
  pump_target: boolean
  battery_pct: number | null
  expected_flow_l_min: number | null
}

export interface OpenAlert {
  id: number
  node_id: number | null
  type: AlertType
  category: AlertCategory
  priority: Priority
  task_ready: boolean
  data: Record<string, unknown> | null
  created_at: string
}

export interface ParkDetail extends Park {
  nodes: ParkNode[]
  open_alerts: OpenAlert[]
  open_tasks: Task[]
  pump: PumpState | null
}

export type ReadingRange = '1h' | '24h' | '7d'

export interface ReadingBucket {
  t: string
  moisture_pct: number | null
  temperature: number | null
  humidity: number | null
  flow: number | null
  battery_pct: number | null
  readings: number
}

export interface ReadingSeries {
  range: ReadingRange
  bucket_seconds: number
  from: string
  to: string
  source: Source
  data: ReadingBucket[]
}

export interface ValidationErrorBody {
  message: string
  errors?: Record<string, string[]>
}

// ---- Sprint 2: alerts, tasks, pump, workers, demo ----

export type AlertType =
  | 'dry'
  | 'heat_stress'
  | 'over'
  | 'no_flow'
  | 'leak'
  | 'blockage'
  | 'offline'
  | 'sensor_fault'
  | 'low_battery'
  | 'litter'
  | 'ndvi_drop'
/** What the problem is and who handles it (derived from the type on the server). */
export type AlertCategory = 'water' | 'plant' | 'cleanliness' | 'devices'
export type TaskStatus = 'open' | 'in_progress' | 'done' | 'cancelled'
export type TaskPriority = 'low' | 'normal' | 'high' | 'urgent'
/** Priority of an alert and of its task. Users see the priority only (severity stays on the server). */
export type Priority = TaskPriority
export type PumpReason = 'manual' | 'task' | 'rule' | 'system' | 'safety'
export type VerifyReason = 'condition_returned' | 'not_recovered' | 'no_data'

export interface PersonRef {
  id: number
  name: string
}

export interface PumpState {
  node_id: number
  target: boolean
  reported_on: boolean | null
  changed_at: string | null
  reason: PumpReason | null
  by: PersonRef | null
  cutoff_at: string | null
  max_run_seconds: number
}

export interface Alert {
  id: number
  park_id: number
  park?: { id: number; name_ar: string; name_en: string; source: Source; auto_irrigation: boolean }
  node_id: number | null
  type: AlertType
  category: AlertCategory
  priority: Priority
  task_ready: boolean
  task_ready_at: string | null
  data: Record<string, unknown> | null
  recommended_action: string
  status: 'open' | 'resolved'
  task: {
    id: number
    status: TaskStatus
    priority: Priority
    due_at: string | null
    overdue: boolean
    verified: boolean | null
    assigned_to: PersonRef | null
  } | null
  created_at: string
  resolved_at: string | null
}

export interface Task {
  id: number
  park_id: number
  park?: { id: number; name_ar: string; name_en: string; district: string | null; status: ParkStatus; source: Source }
  alert_id: number | null
  alert?: {
    id: number
    type: AlertType
    category: AlertCategory
    priority: Priority
    status: 'open' | 'resolved'
    data: Record<string, unknown> | null
    recommended_action: string
    created_at: string
  } | null
  title: string
  note: string | null
  category?: AlertCategory | null
  priority: TaskPriority
  status: TaskStatus
  due_at: string | null
  overdue: boolean
  escalated_at: string | null
  escalation_count: number
  assigned_to?: PersonRef | null
  created_by?: PersonRef | null
  created_at: string
  started_at: string | null
  done_at: string | null
  done_note: string | null
  has_photo: boolean
  verified: boolean | null
  verified_at: string | null
  verify_reason: VerifyReason | null
  time_to_resolve_minutes: number | null
}

export interface WorkerTaskDetail extends Task {
  park_detail: Park
  pump: PumpState | null
}

export interface Worker {
  id: number
  name: string
  phone: string | null
  open_tasks: number
  in_progress_tasks: number
}

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; per_page: number; total: number; last_page: number }
}

export type ScenarioName = 'reset' | 'dry' | 'heat' | 'leak' | 'blockage' | 'battery' | 'offline' | 'escalate' | 'recover'

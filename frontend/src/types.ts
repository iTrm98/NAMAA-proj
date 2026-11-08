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
}

export interface OpenAlert {
  id: number
  node_id: number | null
  type: string
  severity: 'info' | 'warning' | 'critical'
  data: Record<string, unknown> | null
  created_at: string
}

export interface ParkDetail extends Park {
  nodes: ParkNode[]
  open_alerts: OpenAlert[]
}

export type ReadingRange = '24h' | '7d'

export interface ReadingBucket {
  t: string
  moisture_pct: number | null
  temperature: number | null
  humidity: number | null
  flow: number | null
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

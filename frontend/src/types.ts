// API types. They mirror the API contract (v2); change the contract first.

export type Role = 'admin' | 'worker' | 'municipality'
export type Source = 'real' | 'simulated'
export type ParkStatus = 'ok' | 'dry' | 'over' | 'offline' | 'awaiting_install' | 'no_sensor'
export type Locale = 'ar' | 'en'

export interface User {
  id: number
  name: string
  email: string
  role: Role
  company_id: number | null
  company: { id: number; name: string } | null
  locale: Locale
  name_ar?: string | null
}

export interface LoginResponse {
  token: string
  user: User
}

export interface GeoJsonPolygon {
  type: 'Polygon'
  coordinates: [number, number][][] // [lng, lat]
}

export interface GeoJsonMultiPolygon {
  type: 'MultiPolygon'
  coordinates: [number, number][][][]
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
  /** Automatic irrigation is running right now (backend flag; falls back to auto_irrigation + pump_target). */
  auto_irrigation_active?: boolean
  open_alerts_count: number
  latest: LatestValues | null
  /** The park's camera and its last frame (null without a camera). */
  camera: { node_id: number; name: string; source: Source; last_count: number | null; last_frame_at: string | null } | null
  /** NDVI is being recomputed after an outline change (Sprint 3.1). */
  ndvi_recomputing: boolean
  /** Newest Sentinel-2 NDVI value (map vegetation layer); null before the first fetch. */
  ndvi: ParkNdvi | null
  company?: { id: number; name: string }
  /** Sprint 6.2 Phase A (optional until the backend ships them). */
  site_type?: 'park' | 'demo_stand'
  monitoring_mode?: 'real_sensors' | 'simulated' | 'satellite_only'
  geometry_source?: string | null
  verification_status?: string | null
  boundary?: GeoJsonPolygon | null
  green_zone?: GeoJsonMultiPolygon | null
  boundary_area_m2?: number | null
  green_zone_area_m2?: number | null
  demo_role?: string | null
  cover_photo?: { url: string; at: string } | null
  archived_at?: string | null
}

export interface ParkNode {
  id: number
  name: string
  type: 'sensor' | 'camera'
  last_seen_at: string | null
  online: boolean
  /** Sprint 6: awaiting_install for a real node that never reported. */
  status?: 'awaiting_install' | 'online' | 'offline'
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
  detection_id: number | null
  /** The camera frame that raised (or upgraded) a litter alert. */
  detection: Detection | null
  data: Record<string, unknown> | null
  created_at: string
}

export interface ParkDetail extends Park {
  nodes: ParkNode[]
  open_alerts: OpenAlert[]
  open_tasks: Task[]
  pump: PumpState | null
  /** Sprint 3.1: where the outline and the area come from. */
  sources: { outline: DataSourceInfo; area: DataSourceInfo }
  /** Sprint 6.2 Phase A: sourced facts about the park (detail only). */
  claims?: ParkClaim[]
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
  | 'litter_repeated'
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
  name_ar?: string | null
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
  park?: { id: number; name_ar: string; name_en: string; source: Source; auto_irrigation: boolean; company?: { id: number; name: string } }
  node_id: number | null
  type: AlertType
  category: AlertCategory
  priority: Priority
  task_ready: boolean
  task_ready_at: string | null
  detection_id: number | null
  /** Litter: the camera frame that raised (or upgraded) the alert. */
  detection?: Detection | null
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
    detection_id?: number | null
    detection?: Detection | null
    /** Worker route of the litter photo (`/worker/tasks/{id}/frame`); null without a frame. */
    frame_url?: string | null
    /** The node the alert came from; null for park-level alerts. */
    node?: { id: number; name: string; type: string; source: Source; last_seen_at: string | null } | null
    recommended_action: string
    created_at: string
  } | null
  /** Kanban column (Sprint 6, B4). */
  board?: 'todo' | 'in_progress' | 'checking' | 'closed'
  title: string
  /** i18n path of a system title (taskTitle.dry...); null for a free-text title. */
  title_key?: string | null
  title_park_id?: number | null
  title_park?: { id: number; name_ar: string; name_en: string } | null
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
  /** Dry / heat-stress tasks: water until this moisture % (middle of the park range). */
  watering_target_pct: number | null
  done_moisture_pct: number | null
  /** Done was pressed below the watering target (allowed, recorded). */
  done_below_target: boolean | null
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
  name_ar?: string | null
  phone: string | null
  open_tasks: number
  in_progress_tasks: number
}

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; per_page: number; total: number; last_page: number }
}

export type ScenarioName = 'reset' | 'dry' | 'heat' | 'leak' | 'blockage' | 'battery' | 'offline' | 'escalate' | 'litter' | 'recover'

// ---- Sprint 3: camera frames ----

export interface DetectionItem {
  label: string
  confidence: number
  /** [x1, y1, x2, y2] in pixels of the stored photo */
  box: [number, number, number, number]
}

export interface Detection {
  id: number
  park_id: number
  node_id: number | null
  count: number
  items: DetectionItem[]
  people_count: number
  model: string | null
  inference_ms: number | null
  image_w: number | null
  image_h: number | null
  origin: 'camera' | 'upload' | 'scenario' | 'browser'
  /** API path (fetched with the Bearer token; never a public URL) */
  image_url: string
  /** Small and display-size variants (api.md); the UI never loads the full-size original. */
  thumb_url?: string
  display_url?: string
  captured_at: string
}

/** One Sentinel-2 NDVI value of a park (GET /parks/{id}/ndvi). */
export interface NdviPoint {
  date: string
  /** Null when the park is too small to measure reliably. */
  mean: number | null
  pixels: number
  source: 'sentinel-2'
  too_small: boolean
}

/** A park's newest NDVI; mean is null when too_small (fewer than 20 clear pixels). */
export interface ParkNdvi {
  mean: number | null
  date: string
  pixels: number
  too_small: boolean
}

/** Progress of the demo page's "Fetch satellite data now" run. */
export interface NdviRun {
  id: string
  days: number
  total: number
  done: number
  failed: number
  values: number
  parks: Record<string, 'queued' | 'done' | 'skipped' | 'failed'>
  started_at: string
  finished_at: string | null
}

/**
 * Water figures (impact, Sprint 3.1): NAMAA's pump time against a fixed irrigation
 * schedule over the same measured time; parks with a pump and automatic irrigation only.
 */
export interface ImpactWater {
  method: 'pump_time_vs_schedule'
  schedule: { sessions_per_day: number; minutes_per_session: number }
  measured_hours: number
  pump_min: number
  baseline_min: number
  saved_min: number | null
  saved_pct: number | null
  used_l: number
  baseline_l: number
  saved_l: number | null
  parks_measured: number
  parks_measured_real: number
  /** False until >= min_cycles completed pump cycles and min_measured_hours measured hours. */
  enough_data: boolean
  cycles: number
  min_cycles: number
  min_measured_hours: number
  /** Totals only: parks left out because they do not have enough data yet. */
  parks_not_enough_data?: number
  /** One park only: why it is not compared. */
  not_compared: 'no_pump' | 'manual_irrigation' | 'no_readings' | 'not_enough_data' | null
  since: string | null
}

export interface ImpactTasks {
  done: number
  verified: number
  not_verified: number
  pending: number
  verified_rate: number | null
  median_resolve_min: number | null
  escalated: number
  overdue: number
  water_used_l: number
}

/** GET /api/{admin|municipality}/impact?days= */
export interface Impact {
  period: { days: number; from: string; to: string }
  water: ImpactWater
  alerts: {
    total: number
    by_priority: Record<Priority, number>
    by_category: Record<AlertCategory, number>
    by_type: Partial<Record<AlertType, number>>
  }
  tasks: ImpactTasks
  source_mix: { real: number; simulated: number }
  parks: {
    id: number
    name_ar: string
    name_en: string
    source: Source
    company: { id: number; name: string } | null
    water: ImpactWater
    alerts: number
    tasks: ImpactTasks
    ndvi: ParkNdvi | null
  }[]
  /** Municipality only. */
  by_company?: {
    company: { id: number; name: string }
    parks: number
    source_mix: { real: number; simulated: number }
    water: ImpactWater
    alerts: number
    alerts_urgent: number
    tasks: ImpactTasks
  }[]
}

// ---- Sprint 3.1: data sources ----

export type DataSourceType = 'official' | 'traced' | 'osm' | 'approximate'

/** Where a park's outline or area comes from. */
export interface DataSourceInfo {
  type: DataSourceType
  reference: string | null
  by: { id: number; name: string } | null
  at: string | null
}

// ---- hardware (Sprint 4, Phase C): GET /api/admin/nodes ----

export interface HardwareReading {
  at: string
  moisture_raw: number | null
  moisture_pct: number | null
  temperature: number | null
  humidity: number | null
  flow: number | null
  water_level: number | null
  battery_pct: number | null
  pump_on: boolean | null
}

export interface HardwareNode {
  id: number
  name: string
  type: 'sensor' | 'camera'
  source: Source
  park: { id: number; name_en: string; name_ar: string; is_test: boolean } | null
  has_pump: boolean
  online: boolean
  status?: 'awaiting_install' | 'online' | 'offline'
  last_seen_at: string | null
  seconds_since_seen: number | null
  expected_interval_s: number
  offline_after_s: number
  rssi: number | null
  uptime_s: number | null
  fw_version: string | null
  via: 'wifi' | 'usb' | null
  last_reading: HardwareReading | null
  calibration: { dry_value: number; wet_value: number; latest_raw: number | null; latest_pct: number | null } | null
  pump: { target: boolean; reported_on: boolean | null; test_until: string | null } | null
  camera: { capture_mode: 'url' | 'browser'; camera_url: string | null; effective_url: string | null; last_frame_at: string | null; last_count: number | null } | null
}

export interface DeviceRejections {
  node_id: number | null
  node_name: string | null
  requests: { at: string; status: number; message: string | null }[]
}

export interface NodeToken {
  node_id: number
  name: string
  token: string
  serial_commands: string[]
  secrets_block: string
}

export interface CameraTestResult {
  ok: boolean
  ms: number
  bytes: number
  content_type: string
  image: string
}

// ---- Sprint 6: overview ----

type OverviewPark = { id: number; name_ar: string; name_en: string; source: Source; company?: { id: number; name: string } }

/** GET /api/admin/overview (and the municipality twin). */
export interface Overview {
  kpis: {
    parks: { total: number; real: number; simulated: number }
    open_alerts: { total: number; by_priority: Record<Priority, number> }
    fixes: { days: number; done: number; verified: number; not_verified: number; pending: number; verified_pct: number | null }
    median_fix_minutes: number | null
  }
  alerts_by_category: Record<AlertCategory, number>
  right_now: { parks_needing_water: number; open_leaks: number; open_litter: number; sensors_awaiting_installation: number }
  needs_attention: {
    alerts: {
      id: number
      park: OverviewPark
      type: AlertType
      category: AlertCategory
      priority: Priority
      task_ready: boolean
      created_at: string
      task: { id: number; status: TaskStatus; assigned_to: PersonRef | null } | null
    }[]
    tasks_in_progress: {
      id: number
      title: string
      title_key: string | null
      title_park_id: number | null
      park: OverviewPark
      priority: TaskPriority
      assigned_to: PersonRef | null
      started_at: string | null
      watering: { start_pct: number; current_pct: number; target_pct: number; progress_pct: number } | null
    }[]
    /** Full sizes of the three groups (the lists are capped); falls back to the list lengths. */
    counts?: { alerts: number; tasks_in_progress: number; setup: number }
    setup: { type: 'awaiting_installation' | 'outline_unverified'; park: OverviewPark; node: { id: number; name: string; type: string } | null }[]
  }
  /** Sprint 6.2: the demo stand (never a park); admin only, null when there is none. */
  demo_stand?: { park_id: number; name_ar: string; name_en: string; online: boolean; latest: { moisture_pct: number | null; temperature_c: number | null; humidity_pct: number | null; at: string } | null } | null
  by_company?: { company: { id: number; name: string }; parks: number; open_alerts: number; urgent_alerts: number }[]
}

/** GET /admin/parks/{id}/timeline (Sprint 6, B3): the proof chain of the park's latest problem. */
export interface TimelineActor {
  id: number
  name: string
  name_ar?: string | null
}
export interface TimelineStep {
  key: 'alert_raised' | 'task_created' | 'task_assigned' | 'task_started' | 'pump_on' | 'pump_off' | 'task_done' | 'verified' | 'not_verified' | 'task_cancelled' | 'alert_resolved'
  at: string
  actor: TimelineActor | null
  data: Record<string, unknown> | null
}
export interface ParkTimeline {
  alert: { id: number; type: AlertType; category: AlertCategory; priority: Priority; status: 'open' | 'resolved'; created_at: string; resolved_at: string | null }
  task: { id: number; title: string; title_key: string | null; status: TaskStatus; verified: boolean | null; assigned_to: TimelineActor | null } | null
  steps: TimelineStep[]
  duration_minutes: number | null
}

/** Sprint 6.2 Phase A: park setup (GET /admin/park-setup/osm and /selection, claims). */
export type SetupMode = 'simulated' | 'real_sensors' | 'satellite_only'

/** A GeoJSON feature as the setup endpoints send it; geometry is passed through untouched. */
export interface SetupGeoFeature {
  type: 'Feature'
  geometry: unknown
  properties: {
    osm_id?: string | null
    slug?: string | null
    name?: string | null
    'name:ar'?: string | null
    'name:en'?: string | null
    name_ar?: string | null
    name_en?: string | null
    district?: string | null
    area_m2?: number | null
    monitoring_mode?: SetupMode | null
    claims?: unknown[]
  }
}

export interface FeatureCollectionOf {
  type: 'FeatureCollection'
  features: SetupGeoFeature[]
}

export interface OsmExport {
  file: string
  fetched_at: string | null
  features: number
  ge_1ha: number
  geojson: FeatureCollectionOf
}

export interface SelectionResponse extends FeatureCollectionOf {
  simulated: number
  cap: number
}

export interface ParkClaim {
  id: number
  field: string
  value: string
  source_name: string | null
  source_url: string | null
  source_date: string | null
  note: string | null
}

export type ParkClaimInput = Omit<ParkClaim, 'id'>

/** GET /admin/demo/story (Sprint 6.2): the park each demo step runs on; null when none is set up. */
export type DemoStoryEntry = { park_id: number; name_ar: string; name_en: string; node: { id: number; name: string; source: Source } } | null
export interface DemoStory {
  dry: DemoStoryEntry
  leak: DemoStoryEntry
  offline: DemoStoryEntry
  litter: DemoStoryEntry
}

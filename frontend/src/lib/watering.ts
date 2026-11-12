import type { WorkerTaskDetail } from '@/types'

export interface Watering {
  from: number
  target: number
  current: number | null
  /** 0-100 between the moisture when the alert was raised and the target. */
  progress: number
  reached: boolean
}

/** "Water until X %" progress of a dry / heat-stress task, or null for other tasks. */
export function wateringOf(task: WorkerTaskDetail): Watering | null {
  if (task.watering_target_pct == null) return null
  const target = task.watering_target_pct
  const current = task.park_detail.latest?.moisture_pct ?? null
  const raised = Number(task.alert?.data?.moisture_pct)
  const from = Number.isFinite(raised) && raised < target ? raised : Math.min(task.park_detail.moisture_min, target)
  const span = Math.max(target - from, 1)
  const progress = current == null ? 0 : Math.min(100, Math.max(0, ((current - from) / span) * 100))
  return { from, target, current, progress, reached: current != null && current >= target }
}

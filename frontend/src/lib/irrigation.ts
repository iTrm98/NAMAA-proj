import type { Park } from '@/types'

/** True while the park waters itself (automatic irrigation on and the pump commanded on). */
export function isAutoWatering(park: Pick<Park, 'auto_irrigation' | 'pump_target' | 'auto_irrigation_active'>): boolean {
  return park.auto_irrigation_active ?? (park.auto_irrigation && park.pump_target === true)
}

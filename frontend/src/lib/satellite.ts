import type { ChipTone } from '@/components/ds'
import type { Park, ParkSatellite } from '@/types'

export type ConfidenceLevel = ParkSatellite['confidence']['level']

/** High green, Medium lime, Low amber, Not measurable grey. */
export const CONFIDENCE_TONE: Record<ConfidenceLevel, ChipTone> = { high: 'ok', medium: 'lime', low: 'high', not_measurable: 'muted' }

/** First problem reason of a satellite reading (low confidence, or not measurable), or null. */
export function firstReason(s: ParkSatellite | null | undefined): ParkSatellite['confidence']['reasons'][number] | null {
  if (!s) return null
  const level = s.confidence.level
  if (level === 'high') return null
  return s.confidence.reasons[0] ?? null
}

/** Green cover as a short label for a marker ("62%"), or null when not measurable. */
export function greenCoverLabel(park: Park): string | null {
  const v = park.satellite?.measurable ? park.satellite.green_cover_pct : null
  return v == null ? null : `${Math.round(v)}%`
}

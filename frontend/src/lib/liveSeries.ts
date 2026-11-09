import type { QueryClient } from '@tanstack/react-query'
import type { ReadingBucket, ReadingSeries } from '@/types'

/** The live values of a reading.received event. */
export interface LiveReading {
  at: string
  moisture_pct: number | null
  temperature: number | null
  humidity: number | null
  flow: number | null
  battery_pct?: number | null
}

const KEYS = ['moisture_pct', 'temperature', 'humidity', 'flow', 'battery_pct'] as const

/**
 * Put a live reading into a cached chart series without refetching it: into
 * its bucket (running average) or into a new bucket at the end. Skipped
 * buckets are added empty (null), so a gap stays a gap. Buckets that fall out
 * of the window are dropped, so the series keeps its length.
 */
export function appendReading(series: ReadingSeries, reading: LiveReading): ReadingSeries {
  const size = series.bucket_seconds * 1000
  const ts = new Date(reading.at).getTime()
  const bucketStart = Math.floor(ts / size) * size
  const data = series.data.slice()
  const lastStart = data.length ? new Date(data[data.length - 1].t).getTime() : bucketStart - size

  if (bucketStart < lastStart) {
    // An older bucket (late event): update it in place if it is still in the window.
    const index = data.findIndex((b) => new Date(b.t).getTime() === bucketStart)
    if (index === -1) return series
    data[index] = mergeInto(data[index], reading)
    return { ...series, data }
  }

  if (bucketStart === lastStart && data.length) {
    data[data.length - 1] = mergeInto(data[data.length - 1], reading)
    return { ...series, data }
  }

  for (let start = lastStart + size; start < bucketStart; start += size) {
    data.push(emptyBucket(start))
  }
  data.push(mergeInto(emptyBucket(bucketStart), reading))

  const windowStart = bucketStart - (new Date(series.to).getTime() - new Date(series.from).getTime())
  const kept = data.filter((b) => new Date(b.t).getTime() >= Math.floor(windowStart / size) * size)

  return { ...series, to: new Date(Math.max(ts, new Date(series.to).getTime())).toISOString(), data: kept }
}

function emptyBucket(start: number): ReadingBucket {
  return { t: new Date(start).toISOString().replace('.000Z', 'Z'), moisture_pct: null, temperature: null, humidity: null, flow: null, battery_pct: null, readings: 0 }
}

function mergeInto(bucket: ReadingBucket, reading: LiveReading): ReadingBucket {
  const n = bucket.readings
  const next: ReadingBucket = { ...bucket, readings: n + 1 }
  for (const key of KEYS) {
    const value = reading[key]
    if (value == null) continue
    const old = bucket[key]
    next[key] = old == null || n === 0 ? value : (old * n + value) / (n + 1)
  }
  return next
}

/** Append a live reading to every cached range of a park (1 h, 24 h, 7 d). */
export function appendToCachedSeries(queryClient: QueryClient, parkId: number, reading: LiveReading) {
  for (const [key, series] of queryClient.getQueriesData<ReadingSeries>({ queryKey: ['admin', 'parks', parkId, 'readings'] })) {
    if (series) queryClient.setQueryData<ReadingSeries>(key, appendReading(series, reading))
  }
}

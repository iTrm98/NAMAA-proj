import i18n from '@/i18n'

/** Display times in Makkah time (Asia/Riyadh), Gregorian calendar, Latin digits. */
const TIME_ZONE = 'Asia/Riyadh'

function locale() {
  return i18n.language === 'ar' ? 'ar-SA-u-ca-gregory-nu-latn' : 'en-GB'
}

export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  return new Intl.DateTimeFormat(locale(), {
    timeZone: TIME_ZONE,
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(iso))
}

export function formatTime(iso: string, withDay = false): string {
  return new Intl.DateTimeFormat(locale(), {
    timeZone: TIME_ZONE,
    ...(withDay ? { weekday: 'short' } : {}),
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).format(new Date(iso))
}

export function formatNumber(value: number | null | undefined, digits = 1): string {
  if (value === null || value === undefined) return '—'
  return new Intl.NumberFormat(locale(), { maximumFractionDigits: digits, minimumFractionDigits: 0 }).format(value)
}

/** Relative "x min ago" for last-seen labels. */
export function formatAgo(iso: string | null | undefined): string {
  if (!iso) return '—'
  const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60_000)
  const rtf = new Intl.RelativeTimeFormat(i18n.language === 'ar' ? 'ar-u-nu-latn' : 'en', { numeric: 'auto' })
  if (Math.abs(minutes) < 60) return rtf.format(-minutes, 'minute')
  const hours = Math.round(minutes / 60)
  if (Math.abs(hours) < 48) return rtf.format(-hours, 'hour')
  return rtf.format(-Math.round(hours / 24), 'day')
}

/** A duration in words ("2 h 05 min", "3 min", "45 s"); seconds >= 0. */
export function formatDuration(seconds: number): string {
  const s = Math.max(0, Math.round(seconds))
  if (s < 60) return i18n.t('common.durationS', { s })
  const m = Math.floor(s / 60)
  if (m < 60) return i18n.t('common.durationM', { m })
  const h = Math.floor(m / 60)
  if (h < 48) return i18n.t('common.durationH', { h, m: String(m % 60).padStart(2, '0') })
  return i18n.t('common.durationD', { d: Math.floor(h / 24), h: h % 24 })
}

import type { TFunction } from 'i18next'
import { useTranslation } from 'react-i18next'
import { Chip } from '@/components/ds'
import { CONFIDENCE_TONE, firstReason } from '@/lib/satellite'
import { formatDate, formatNumber } from '@/lib/format'
import { isSatelliteOnly } from '@/lib/parkClusters'
import type { Park, ParkSatellite } from '@/types'

export function ConfidenceChip({ level }: { level: ParkSatellite['confidence']['level'] }) {
  const { t } = useTranslation()
  return (
    <Chip tone={CONFIDENCE_TONE[level]}>
      {t(`satellitePopup.conf_${level}`)}
    </Chip>
  )
}

/** The reason line of a reading, as an i18n sentence. */
function reasonText(s: ParkSatellite, t: TFunction): string | null {
  const r = firstReason(s)
  return r ? t(`satellitePopup.reason_${r.code}`, { ...(r.params ?? {}) }) : null
}

function ageText(days: number | null, t: TFunction): string {
  return days == null ? '' : days <= 0 ? t('satellitePopup.today') : t('satellitePopup.daysAgo', { count: days })
}

/** Block 1: the sensor, live. Never shows satellite values. */
export function SensorBlock({ park }: { park: Park }) {
  const { t } = useTranslation()
  const m = park.latest?.moisture_pct
  const satOnly = isSatelliteOnly(park)
  return (
    <section className="flex flex-col gap-1 rounded-xl bg-white/[.05] p-3" aria-label={t('satellitePopup.sensor_title')}>
      <h4 className="m-0 text-[11px] font-medium tracking-wide text-[var(--text-muted)] uppercase">{t('satellitePopup.sensor_title')}</h4>
      {satOnly ? (
        <p className="m-0 text-[13px] text-[var(--chip-muted-fg)]">{t('satellitePopup.noSensor')}</p>
      ) : m != null ? (
        <>
          <span className="text-[32px] leading-none font-semibold tabular-nums">{formatNumber(m)}%</span>
          <span className="text-[11px] text-[var(--text-muted)]">{t('satellitePopup.moistureCaption', { min: formatNumber(park.moisture_min) })}</span>
          {park.source === 'simulated' && <span className="text-[11px] text-[var(--text-muted)]">{t('satellitePopup.simulated')}</span>}
        </>
      ) : (
        <p className="m-0 text-[13px] text-[var(--chip-muted-fg)]">{t(`status.${park.status}`)}</p>
      )}
    </section>
  )
}

/** Block 2: the satellite reading with its trust level. The big number is green cover %. */
export function SatelliteBlock({ park }: { park: Park }) {
  const { t } = useTranslation()
  const s = park.satellite
  return (
    <section className="flex flex-col gap-1.5 rounded-xl bg-white/[.05] p-3" aria-label={t('satellitePopup.sat_title')}>
      <h4 className="m-0 text-[11px] font-medium tracking-wide text-[var(--text-muted)] uppercase">{t('satellitePopup.sat_title')}</h4>
      {!s ? (
        <p className="m-0 text-[13px] text-[var(--chip-muted-fg)]">{t('satellitePopup.noImage')}</p>
      ) : !s.measurable || s.green_cover_pct == null ? (
        <>
          <p className="m-0 text-[13px] text-[var(--chip-muted-fg)]">
            {t('satellitePopup.notMeasurable', { reason: t(`satellitePopup.reason_${s.confidence.reasons[0]?.code ?? 'too_small'}`) })}
          </p>
          <ImageLine s={s} />
        </>
      ) : (
        <>
          <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
            <span className="text-[32px] leading-none font-semibold tabular-nums">{formatNumber(s.green_cover_pct, 0)}%</span>
            <ConfidenceChip level={s.confidence.level} />
          </div>
          <span className="text-[11px] text-[var(--text-muted)]">
            {t('satellitePopup.cover')} · {t('satellitePopup.estimated')}
          </span>
          <p className="m-0 flex flex-wrap gap-x-2 text-[12px] text-[var(--chip-muted-fg)] tabular-nums">
            {s.change_vs_normal_pct != null && (
              <span dir="ltr">{t('satellitePopup.vsNormal', { value: `${s.change_vs_normal_pct > 0 ? '+' : ''}${formatNumber(s.change_vs_normal_pct, 0)}%` })}</span>
            )}
            {s.ndvi != null && (
              <span>
                {t('satellitePopup.ndvi', { value: formatNumber(s.ndvi, 2) })}
                {s.ndvi_word ? ` · ${t(`satellitePopup.word_${s.ndvi_word}`)}` : ''}
              </span>
            )}
          </p>
          <ImageLine s={s} />
          {reasonText(s, t) && (
            <p className="m-0 text-[12px] text-[var(--clean)]" role="note">
              ⚠ {reasonText(s, t)}
            </p>
          )}
        </>
      )}
    </section>
  )
}

function ImageLine({ s }: { s: ParkSatellite }) {
  const { t } = useTranslation()
  if (!s.image_date) return null
  return (
    <span className="text-[12px] text-[var(--text-muted)]">
      {t('satellitePopup.image', { date: formatDate(s.image_date, true), ago: ageText(s.age_days, t) })}
    </span>
  )
}

/** Compact form for the Overview side card (date, green cover %, confidence). */
export function SatelliteSummary({ park }: { park: Park }) {
  return (
    <div className="flex flex-col gap-2">
      <SensorBlock park={park} />
      <SatelliteBlock park={park} />
    </div>
  )
}

/** Legend lines shared by the maps in vegetation mode: newest image date and the confidence key. */
export function SatelliteLegendLines({ imageDate }: { imageDate: string | null }) {
  const { t } = useTranslation()
  return (
    <>
      {imageDate && (
        <span className="text-text-2">
          <bdi>{t('satellitePopup.legendNewest', { date: formatDate(imageDate, true) })}</bdi>
        </span>
      )}
      <span className="flex flex-wrap items-center gap-1 pt-0.5">
        <ConfidenceChip level="high" />
        <ConfidenceChip level="medium" />
        <ConfidenceChip level="low" />
        <ConfidenceChip level="not_measurable" />
      </span>
    </>
  )
}

import { useTranslation } from 'react-i18next'
import { type Basemap, MAPTILER_KEY } from '@/lib/mapStyle'
import { cn } from '@/lib/utils'

/** True when the basemap is a satellite photo (MapTiler satellite or Esri). */
export function isSatellite(basemap: Basemap): boolean {
  return basemap === 'esri' || (basemap === 'satellite' && !!MAPTILER_KEY)
}

/** "Background photo · not live · date varies": shown on every satellite background. */
export function SatelliteNote({ basemap, className }: { basemap: Basemap; className?: string }) {
  const { t, i18n } = useTranslation()
  if (!isSatellite(basemap)) return null
  return (
    <div
      dir={i18n.dir()}
      className={cn('pointer-events-none absolute start-2 bottom-2 z-[1] rounded-full bg-[rgba(16,18,14,.78)] px-2.5 py-1 text-[11px] text-white/80', className)}
    >
      {t('map.satelliteNote')}
    </div>
  )
}

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ImagePlus, Trash2 } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Chip, GlassCard, Skeleton, toast } from '@/components/ds'
import { parkName } from '@/hooks/useParks'
import { api, ApiError, apiBlob, isAbortError } from '@/lib/api'
import { formatDate, formatNumber } from '@/lib/format'
import type { ParkDetail } from '@/types'

const MAX_BYTES = 5 * 1024 * 1024
const TYPES = ['image/jpeg', 'image/png', 'image/webp']

/** "OSM" / "Traced by NAMAA" / "Rough outline" (parks.geometry_source). */
export function GeometryBadge({ source }: { source: string | null | undefined }) {
  const { t } = useTranslation()
  if (!source) return null
  const key = source === 'osm' || source === 'traced' ? source : 'rough_legacy'
  return <Chip tone={key === 'traced' ? 'lime' : key === 'osm' ? 'muted' : 'high'}>{t(`parkAbout.geo.${key}`)}</Chip>
}

/** "Verified" / "Unverified" (parks.verification_status). */
export function VerifiedChip({ status }: { status: string | null | undefined }) {
  const { t } = useTranslation()
  if (!status) return null
  return <Chip tone={status === 'verified' ? 'ok' : 'muted'}>{t(status === 'verified' ? 'parkAbout.verified' : 'parkAbout.unverified')}</Chip>
}

/** The cover photo, fetched with the Bearer token (never a public URL). */
function CoverPhoto({ park, readOnly }: { park: ParkDetail; readOnly: boolean }) {
  const { t, i18n } = useTranslation()
  const qc = useQueryClient()
  const input = useRef<HTMLInputElement>(null)
  const [url, setUrl] = useState<string | null>(null)
  const hasPhoto = !!park.cover_photo

  const photo = useQuery({
    queryKey: [readOnly ? 'municipality' : 'admin', 'parks', park.id, 'photo', park.cover_photo?.at ?? null],
    queryFn: ({ signal }) => apiBlob(`/${readOnly ? 'municipality' : 'admin'}/parks/${park.id}/photo`, signal),
    enabled: hasPhoto,
    staleTime: Infinity,
    retry: false,
  })

  useEffect(() => {
    if (!photo.data) {
      setUrl(null)
      return
    }
    const next = URL.createObjectURL(photo.data)
    setUrl(next)
    return () => URL.revokeObjectURL(next)
  }, [photo.data])

  const refresh = () => {
    void qc.invalidateQueries({ queryKey: ['admin', 'parks', park.id] })
    void qc.invalidateQueries({ queryKey: ['admin', 'parks'] })
  }
  const fail = (e: unknown) => {
    if (isAbortError(e)) return
    toast.error(e instanceof ApiError && e.body?.message ? e.body.message : t('parkAbout.loadError'))
  }

  const upload = useMutation({
    mutationFn: (file: File) => {
      const body = new FormData()
      body.append('photo', file)
      return api<unknown>(`/admin/parks/${park.id}/photo`, { method: 'POST', body })
    },
    onSuccess: () => {
      toast.success(t('parkAbout.photoUploaded'))
      refresh()
    },
    onError: fail,
  })
  const remove = useMutation({
    mutationFn: () => api<unknown>(`/admin/parks/${park.id}/photo`, { method: 'DELETE' }),
    onSuccess: () => {
      toast.success(t('parkAbout.photoDeleted'))
      refresh()
    },
    onError: fail,
  })

  const pick = (file: File | undefined) => {
    if (input.current) input.current.value = ''
    if (!file) return
    if (!TYPES.includes(file.type) || file.size > MAX_BYTES) {
      toast.error(t('parkAbout.photoFormat'))
      return
    }
    upload.mutate(file)
  }

  const busy = upload.isPending || remove.isPending
  const btn =
    'inline-flex min-h-11 items-center gap-2 rounded-full border border-[var(--glass-border-strong)] px-4 text-sm font-medium disabled:opacity-50 [&>svg]:size-4'

  return (
    <div className="flex flex-col gap-3">
      <h3 className="m-0 text-[15px] font-medium">{t('parkAbout.photo')}</h3>
      {hasPhoto && photo.isPending && <Skeleton h={180} radius={18} />}
      {hasPhoto && photo.isError && (
        <div className="flex flex-col items-start gap-2 text-sm text-text-2" role="alert">
          <span>{t('parkAbout.photoError')}</span>
          <button type="button" className={btn} onClick={() => void photo.refetch()}>
            {t('parkAbout.photoRetry')}
          </button>
        </div>
      )}
      {hasPhoto && url && <img src={url} alt={t('parkAbout.photoAlt', { park: parkName(park, i18n.language) })} className="max-h-56 w-full rounded-[18px] object-cover" />}
      {!hasPhoto && !readOnly && <p className="m-0 text-sm text-text-2">{t('parkAbout.photoNone')}</p>}
      {!readOnly && (
      <div className="flex flex-wrap gap-2">
        <input ref={input} type="file" accept={TYPES.join(',')} className="sr-only" tabIndex={-1} aria-hidden onChange={(e) => pick(e.target.files?.[0])} />
        <button type="button" className={btn} disabled={busy} onClick={() => input.current?.click()}>
          <ImagePlus aria-hidden />
          {hasPhoto ? t('parkAbout.photoReplace') : t('parkAbout.photoAdd')}
        </button>
        {hasPhoto && (
          <button type="button" className={btn} disabled={busy} onClick={() => remove.mutate()}>
            <Trash2 aria-hidden />
            {t('parkAbout.photoDelete')}
          </button>
        )}
      </div>
      )}
      {!readOnly && <span className="text-xs text-text-2">{t('parkAbout.photoHint')}</span>}
    </div>
  )
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex min-w-0 flex-col gap-0.5">
      <dt className="text-xs text-text-2">{label}</dt>
      <dd className="m-0 text-sm font-medium tabular-nums">{children}</dd>
    </div>
  )
}

/** "About this park": names, district, areas, outline source, verification, monitoring mode, claims and the cover photo. */
export function ParkAbout({ park, readOnly }: { park: ParkDetail; readOnly: boolean }) {
  const { t } = useTranslation()
  const none = t('parkAbout.notSet')
  const area = (m2: number | null | undefined) => (m2 == null ? none : `${formatNumber(m2, 0)} m²`)
  const claims = park.claims ?? []

  return (
    <GlassCard className="flex flex-col gap-4 lg:col-span-2">
      <h2 className="m-0 text-[17px] font-medium">{t('parkAbout.title')}</h2>
      <div className="grid gap-5 lg:grid-cols-[1.4fr_1fr]">
        <div className="flex min-w-0 flex-col gap-4">
          <dl className="m-0 grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3">
            <Fact label={t('parkAbout.nameAr')}>
              <span dir="rtl">{park.name_ar}</span>
            </Fact>
            <Fact label={t('parkAbout.nameEn')}>
              <span dir="ltr">{park.name_en}</span>
            </Fact>
            <Fact label={t('parkAbout.district')}>{park.district ?? none}</Fact>
            <Fact label={t('parkAbout.boundaryArea')}>{area(park.boundary_area_m2 ?? park.area_m2)}</Fact>
            <Fact label={t('parkAbout.greenArea')}>{area(park.green_zone_area_m2)}</Fact>
            <Fact label={t('parkAbout.mode')}>{park.monitoring_mode ? t(`mode.${park.monitoring_mode}`) : none}</Fact>
          </dl>
          <div className="flex flex-wrap items-center gap-1.5">
            <GeometryBadge source={park.geometry_source} />
            <VerifiedChip status={park.verification_status} />
            {park.name_unconfirmed && <Chip tone="high">{t('parkAbout.nameUnconfirmed')}</Chip>}
          </div>

          <div className="flex flex-col gap-2">
            <h3 className="m-0 text-[15px] font-medium">{t('parkAbout.claims')}</h3>
            {claims.length === 0 ? (
              <p className="m-0 text-sm text-text-2">{t('parkAbout.claimsEmpty')}</p>
            ) : (
              <ul className="m-0 flex list-none flex-col divide-y divide-[var(--glass-border)] p-0">
                {claims.map((c) => (
                  <li key={c.id} className="flex flex-col gap-0.5 py-2 text-sm">
                    <span>
                      <span className="text-text-2">{c.field}: </span>
                      <span className="font-medium">{c.value}</span>
                    </span>
                    {(c.source_name || c.source_date) && (
                      <span className="text-xs text-text-2">
                        {[c.source_name ? t('parkAbout.claimSource', { name: c.source_name }) : null, c.source_date ? formatDate(c.source_date) : null].filter(Boolean).join(' · ')}
                      </span>
                    )}
                    {c.note && <span className="text-xs text-text-2">{c.note}</span>}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
        {(!readOnly || !!park.cover_photo) && <CoverPhoto park={park} readOnly={readOnly} />}
      </div>
    </GlassCard>
  )
}

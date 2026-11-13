import { useQuery } from '@tanstack/react-query'
import { Download, FileText, Search, Trash2, TreePine } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Chip, Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, EmptyState, GlassCard, Skeleton, toast } from '@/components/ds'
import { useAddClaim, useClaims, useDeleteClaim, useOsmExport, useSaveSelection, useSelection } from '@/hooks/useParkSetup'
import { ApiError, api, isAbortError } from '@/lib/api'
import { formatDateTime, formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { Park, SetupGeoFeature, SetupMode } from '@/types'

const MODES: SetupMode[] = ['satellite_only', 'simulated', 'real_sensors']
const MAX_SIMULATED = 4
const HECTARE = 10_000

type SetupPark = Park & { osm_id?: string | null }

interface Row {
  key: string
  osm_id: string | null
  park_id: number | null
  name_ar: string
  name_en: string
  district: string
  monitoring_mode: SetupMode
  area_m2: number | null
  inSelection: boolean
  geometry: unknown
  claims: unknown[]
}

type Edit = Partial<Pick<Row, 'name_ar' | 'name_en' | 'district' | 'monitoring_mode'>>

const field =
  'h-10 w-full min-w-0 rounded-[14px] border border-[var(--glass-border)] bg-white/[.05] px-3 text-[13px] text-[var(--text)] outline-none placeholder:text-text-2 focus-visible:border-[var(--accent-lime)] focus-visible:ring-2 focus-visible:ring-[var(--accent-lime)]/40'
const th = 'px-3 py-3 text-start text-xs font-medium text-text-2'
const lime = 'inline-flex min-h-11 items-center gap-2 rounded-full bg-[var(--accent-lime)] px-5 text-sm font-medium text-[#14180a] disabled:opacity-50 [&>svg]:size-4'
const ghost = 'inline-flex min-h-11 items-center gap-2 rounded-full border border-[var(--glass-border-strong)] px-5 text-sm font-medium disabled:opacity-50 [&>svg]:size-4'

function modeOf(m: string | null | undefined): SetupMode {
  if (m === 'simulated') return 'simulated'
  if (m === 'real_sensors') return 'real_sensors'
  return 'satellite_only'
}

/** /admin/park-setup: choose which OSM outlines become parks, name them, set their monitoring mode, and record claims. */
export default function ParkSetupPage() {
  const { t, i18n } = useTranslation()
  const osm = useOsmExport()
  const selection = useSelection()
  const save = useSaveSelection()
  const parks = useQuery({
    queryKey: ['admin', 'parks', 'setup'],
    queryFn: ({ signal }) => api<{ data: SetupPark[] }>('/admin/parks', { signal }).then((r) => r.data),
  })

  const [search, setSearch] = useState('')
  const [bigOnly, setBigOnly] = useState(true)
  const [edits, setEdits] = useState<Record<string, Edit>>({})
  const [claimsFor, setClaimsFor] = useState<Row | null>(null)

  const loading = osm.isPending || parks.isPending || selection.isPending
  const failed = osm.isError || parks.isError || selection.isError

  const baseRows = useMemo<Row[]>(() => {
    const byOsm = new Map<string, SetupPark>()
    for (const p of parks.data ?? []) if (p.osm_id) byOsm.set(p.osm_id, p)
    const sel = new Map<string, SetupGeoFeature>()
    for (const f of selection.data?.features ?? []) if (f.properties.osm_id) sel.set(f.properties.osm_id, f)
    const used = new Set<number>()
    const out: Row[] = []
    for (const f of osm.data?.geojson.features ?? []) {
      const o = f.properties
      const osmId = o.osm_id
      if (!osmId) continue
      const p = byOsm.get(osmId)
      const s = sel.get(osmId)?.properties
      if (p) used.add(p.id)
      out.push({
        key: osmId,
        osm_id: osmId,
        park_id: p?.id ?? null,
        name_ar: p?.name_ar ?? s?.name_ar ?? o['name:ar'] ?? o.name ?? '',
        name_en: p?.name_en ?? s?.name_en ?? o['name:en'] ?? '',
        district: p?.district ?? s?.district ?? '',
        monitoring_mode: modeOf(p?.monitoring_mode ?? s?.monitoring_mode),
        area_m2: p?.area_m2 ?? o.area_m2 ?? null,
        inSelection: !!s,
        geometry: f.geometry,
        claims: s?.claims ?? [],
      })
    }
    for (const p of parks.data ?? []) {
      if (used.has(p.id)) continue
      out.push({
        key: `park-${p.id}`,
        osm_id: p.osm_id ?? null,
        park_id: p.id,
        name_ar: p.name_ar,
        name_en: p.name_en,
        district: p.district ?? '',
        monitoring_mode: modeOf(p.monitoring_mode),
        area_m2: p.area_m2,
        inSelection: false,
        geometry: p.boundary ?? p.polygon,
        claims: [],
      })
    }
    return out
  }, [osm.data, parks.data, selection.data])

  const rows = useMemo(() => baseRows.map((r) => ({ ...r, ...edits[r.key] })), [baseRows, edits])
  const simulated = rows.filter((r) => r.monitoring_mode === 'simulated').length
  const cap = selection.data?.cap ?? MAX_SIMULATED
  const dirty = Object.keys(edits).length > 0

  const shown = rows.filter((r) => {
    if (bigOnly && r.park_id == null && (r.area_m2 ?? 0) < HECTARE) return false
    const q = search.trim().toLowerCase()
    return !q || `${r.name_ar} ${r.name_en} ${r.district}`.toLowerCase().includes(q)
  })

  const patch = (key: string, e: Edit) => setEdits((prev) => ({ ...prev, [key]: { ...prev[key], ...e } }))

  const exportSelection = () => {
    const body: SetupGeoFeature[] = rows
      .filter((r) => r.park_id != null || r.inSelection || edits[r.key])
      .map((r) => ({
        type: 'Feature',
        geometry: r.geometry,
        properties: { osm_id: r.osm_id, name_ar: r.name_ar, name_en: r.name_en, district: r.district || null, monitoring_mode: r.monitoring_mode, claims: r.claims },
      }))
    save.mutate(body, {
      onSuccess: () => {
        setEdits({})
        toast.success(t('parkSetup.exported'))
      },
      onError: (e) => {
        if (isAbortError(e)) return
        toast.error(e instanceof ApiError && e.status === 422 ? t('parkSetup.tooManySimulated', { max: cap }) : t('common.error'))
      },
    })
  }

  const retry = () => {
    void osm.refetch()
    void parks.refetch()
    void selection.refetch()
  }

  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-wrap items-end justify-between gap-4">
        <div className="min-w-0">
          <h1 className="m-0 text-[40px] font-light leading-[1.05] tracking-[-.01em]">{t('parkSetup.title')}</h1>
          <p className="m-0 mt-2 max-w-2xl text-sm text-text-2">{t('parkSetup.intro')}</p>
        </div>
        <button type="button" className={lime} disabled={save.isPending || failed || loading} onClick={exportSelection}>
          <Download aria-hidden />
          {t('parkSetup.export')}
        </button>
      </header>

      {loading && (
        <div className="flex flex-col gap-3" aria-busy="true" aria-label={t('common.loading')}>
          <Skeleton h={44} />
          <Skeleton h={320} radius={26} />
        </div>
      )}

      {!loading && failed && (
        <GlassCard>
          <EmptyState
            title={t('common.error')}
            action={
              <button type="button" className={ghost} onClick={retry}>
                {t('common.retry')}
              </button>
            }
          />
        </GlassCard>
      )}

      {!loading && !failed && (
        <>
          <GlassCard padding={16} className="flex flex-wrap items-center gap-3">
            <FileText className="size-4 text-text-2" aria-hidden />
            {osm.data ? (
              <p className="m-0 text-[13px]">
                <span dir="ltr" className="font-medium">
                  {osm.data.file}
                </span>
                <span className="text-text-2"> · {formatDateTime(osm.data.fetched_at)}</span>
                <span className="text-text-2"> · {t('parkSetup.counts', { features: osm.data.features, big: osm.data.ge_1ha })}</span>
              </p>
            ) : (
              <p className="m-0 text-[13px] text-text-2">{t('parkSetup.noOsmHint')}</p>
            )}
            <Chip tone={simulated >= cap ? 'high' : 'muted'} className="ms-auto">
              {t('parkSetup.simulatedCounter', { n: simulated, max: cap })}
            </Chip>
          </GlassCard>

          <div className="flex flex-wrap items-center gap-3">
            <label className="relative min-w-56 flex-1">
              <span className="sr-only">{t('parkSetup.search')}</span>
              <Search className="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-text-2" aria-hidden />
              <input type="search" className={cn(field, 'ps-9')} placeholder={t('parkSetup.search')} value={search} onChange={(e) => setSearch(e.target.value)} />
            </label>
            <label className="inline-flex min-h-11 items-center gap-2 text-[13px]">
              <input type="checkbox" className="size-4 accent-[var(--accent-lime)]" checked={bigOnly} onChange={(e) => setBigOnly(e.target.checked)} />
              {t('parkSetup.bigOnly')}
            </label>
            {dirty && <Chip tone="lime">{t('parkSetup.unsaved')}</Chip>}
          </div>

          {shown.length === 0 ? (
            <GlassCard>
              <EmptyState icon={<TreePine />} title={t('parkSetup.empty')} hint={osm.data ? t('parkSetup.emptyHint') : t('parkSetup.noOsmHint')} />
            </GlassCard>
          ) : (
            <GlassCard radius="sm" padding={0} className="overflow-x-auto">
              <table className="w-full min-w-[920px] border-collapse text-[13px]">
                <thead>
                  <tr className="border-b border-[var(--glass-border)]">
                    <th className={th}>{t('parkSetup.colNameAr')}</th>
                    <th className={th}>{t('parkSetup.colNameEn')}</th>
                    <th className={th}>{t('parkSetup.colDistrict')}</th>
                    <th className={th}>{t('parkSetup.colArea')}</th>
                    <th className={th}>{t('parkSetup.colMode')}</th>
                    <th className={th}>{t('parkSetup.colClaims')}</th>
                  </tr>
                </thead>
                <tbody>
                  {shown.map((r) => (
                    <tr key={r.key} className="border-b border-[var(--glass-border)] last:border-0 hover:bg-white/[.04]">
                      <td className="px-3 py-2">
                        <input className={field} dir="rtl" aria-label={t('parkSetup.colNameAr')} value={r.name_ar} onChange={(e) => patch(r.key, { name_ar: e.target.value })} />
                      </td>
                      <td className="px-3 py-2">
                        <input className={field} dir="ltr" aria-label={t('parkSetup.colNameEn')} value={r.name_en} onChange={(e) => patch(r.key, { name_en: e.target.value })} />
                      </td>
                      <td className="px-3 py-2">
                        <input className={field} aria-label={t('parkSetup.colDistrict')} value={r.district} onChange={(e) => patch(r.key, { district: e.target.value })} />
                      </td>
                      <td className="px-3 py-2 tabular-nums text-text-2">
                        {r.area_m2 != null && t('parkSetup.hectares', { n: formatNumber(r.area_m2 / HECTARE, 1) })}
                        {r.park_id != null && (
                          <Chip tone="ok" className="ms-2">
                            {t('parkSetup.existing')}
                          </Chip>
                        )}
                      </td>
                      <td className="px-3 py-2">
                        <select className={field} aria-label={t('parkSetup.colMode')} value={r.monitoring_mode} onChange={(e) => patch(r.key, { monitoring_mode: e.target.value as SetupMode })}>
                          {MODES.map((m) => (
                            <option key={m} value={m} disabled={m === 'simulated' && r.monitoring_mode !== 'simulated' && simulated >= cap}>
                              {t(`parkSetup.modes.${m}`)}
                            </option>
                          ))}
                        </select>
                      </td>
                      <td className="px-3 py-2">
                        {r.park_id != null ? (
                          <button type="button" className={cn(ghost, 'min-h-10 px-4')} onClick={() => setClaimsFor(r)}>
                            {t('parkSetup.claims')}
                          </button>
                        ) : (
                          <span className="text-xs text-text-2">{t('parkSetup.claimsAfterImport')}</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </GlassCard>
          )}
          <p className="m-0 text-xs text-text-2">{t('parkSetup.note')}</p>
        </>
      )}

      <Dialog open={claimsFor != null} onOpenChange={(o) => !o && setClaimsFor(null)}>
        <DialogContent className="max-w-lg">
          {claimsFor && <ClaimsPanel parkId={claimsFor.park_id!} name={(i18n.language === 'ar' ? claimsFor.name_ar : claimsFor.name_en) || claimsFor.name_ar} />}
        </DialogContent>
      </Dialog>
    </div>
  )
}

const EMPTY_CLAIM = { field: '', value: '', source_name: '', source_url: '', source_date: '', note: '' }

function ClaimsPanel({ parkId, name }: { parkId: number; name: string }) {
  const { t } = useTranslation()
  const claims = useClaims(parkId)
  const add = useAddClaim(parkId)
  const del = useDeleteClaim(parkId)
  const [f, setF] = useState(EMPTY_CLAIM)
  const set = (k: keyof typeof EMPTY_CLAIM) => (e: React.ChangeEvent<HTMLInputElement>) => setF((p) => ({ ...p, [k]: e.target.value }))

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    add.mutate(
      { field: f.field, value: f.value, source_name: f.source_name || null, source_url: f.source_url || null, source_date: f.source_date || null, note: f.note || null },
      {
        onSuccess: () => setF(EMPTY_CLAIM),
        onError: (err) => {
          if (!isAbortError(err)) toast.error(t('common.error'))
        },
      },
    )
  }

  const notFound = claims.error instanceof ApiError && claims.error.status === 404

  return (
    <>
      <DialogHeader>
        <DialogTitle>{t('parkSetup.claimsTitle', { name })}</DialogTitle>
        <DialogDescription>{t('parkSetup.claimsHint')}</DialogDescription>
      </DialogHeader>

      {claims.isPending && <Skeleton h={72} radius={18} />}
      {claims.isError && (
        <EmptyState
          title={notFound ? t('parkSetup.claimsUnavailable') : t('common.error')}
          action={
            notFound ? undefined : (
              <button type="button" className={ghost} onClick={() => void claims.refetch()}>
                {t('common.retry')}
              </button>
            )
          }
        />
      )}
      {claims.data && claims.data.length === 0 && <p className="m-0 text-sm text-text-2">{t('parkSetup.claimsEmpty')}</p>}
      {claims.data && claims.data.length > 0 && (
        <ul className="m-0 flex list-none flex-col gap-2 p-0">
          {claims.data.map((c) => (
            <li key={c.id} className="flex items-start gap-3 rounded-[18px] border border-[var(--glass-border)] p-3 text-[13px]">
              <div className="min-w-0 flex-1">
                <p className="m-0 font-medium">
                  {c.field}: {c.value}
                </p>
                <p className="m-0 text-xs text-text-2">{[c.source_name, c.source_date, c.note].filter(Boolean).join(' · ')}</p>
                {c.source_url && (
                  <a dir="ltr" href={c.source_url} target="_blank" rel="noreferrer" className="block truncate text-xs text-[var(--info)]">
                    {c.source_url}
                  </a>
                )}
              </div>
              <button
                type="button"
                aria-label={t('parkSetup.claimDelete')}
                disabled={del.isPending}
                onClick={() => del.mutate(c.id)}
                className="flex size-11 shrink-0 items-center justify-center rounded-full border border-[var(--glass-border)] disabled:opacity-50 [&>svg]:size-4"
              >
                <Trash2 aria-hidden />
              </button>
            </li>
          ))}
        </ul>
      )}

      <form onSubmit={submit} className="grid grid-cols-2 gap-2">
        <input required className={field} placeholder={t('parkSetup.claimField')} aria-label={t('parkSetup.claimField')} value={f.field} onChange={set('field')} />
        <input required className={field} placeholder={t('parkSetup.claimValue')} aria-label={t('parkSetup.claimValue')} value={f.value} onChange={set('value')} />
        <input className={field} placeholder={t('parkSetup.claimSource')} aria-label={t('parkSetup.claimSource')} value={f.source_name} onChange={set('source_name')} />
        <input type="date" dir="ltr" className={field} aria-label={t('parkSetup.claimDate')} value={f.source_date} onChange={set('source_date')} />
        <input type="url" dir="ltr" className={cn(field, 'col-span-2')} placeholder={t('parkSetup.claimUrl')} aria-label={t('parkSetup.claimUrl')} value={f.source_url} onChange={set('source_url')} />
        <input className={cn(field, 'col-span-2')} placeholder={t('parkSetup.claimNote')} aria-label={t('parkSetup.claimNote')} value={f.note} onChange={set('note')} />
        <button type="submit" className={cn(lime, 'col-span-2 justify-center')} disabled={add.isPending}>
          {t('parkSetup.claimAdd')}
        </button>
      </form>
    </>
  )
}

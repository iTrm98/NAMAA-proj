import { Cpu } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Chip, Disclosure, EmptyState, GlassCard, Skeleton, Toggle } from '@/components/ds'
import { CameraPanel } from '@/components/hardware/CameraPanel'
import { CalibrationPanel, RelayPanel } from '@/components/hardware/SensorPanels'
import { TokenButton } from '@/components/hardware/TokenDialog'
import { loadIncludeTest, saveIncludeTest, useDeviceRejections, useHardwareNodes } from '@/hooks/useHardware'
import { useNow } from '@/hooks/useNow'
import { formatDateTime, formatDuration, formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { DeviceRejections, HardwareNode } from '@/types'

function signal(rssi: number | null): 'excellent' | 'good' | 'fair' | 'weak' | null {
  if (rssi == null) return null
  if (rssi >= -60) return 'excellent'
  if (rssi >= -70) return 'good'
  if (rssi >= -80) return 'fair'
  return 'weak'
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-baseline justify-between gap-3 text-[13px]">
      <dt className="text-text-2">{label}</dt>
      <dd className="m-0 text-end">{children}</dd>
    </div>
  )
}

// The panels carry their own small title; the collapsible header already shows it.
const PANEL = '[&_h3]:hidden'

function NodeCard({ node, rejections, now }: { node: HardwareNode; rejections: DeviceRejections | undefined; now: number }) {
  const { t, i18n } = useTranslation()
  // Ticks client-side from the last post; the server value is only the fallback before the first tick.
  const seen = node.last_seen_at ? Math.max(0, Math.round((now - new Date(node.last_seen_at).getTime()) / 1000)) : null
  const awaiting = node.status === 'awaiting_install' || (node.last_seen_at == null && node.source === 'real')
  const online = !awaiting && seen != null && seen <= node.offline_after_s
  const quality = signal(node.rssi)
  const bench = node.park?.is_test === true
  const rej = rejections?.requests.slice(0, 5) ?? []

  return (
    <GlassCard as="article" padding={18} className="flex min-w-0 flex-col gap-3.5">
      <header className="flex flex-wrap items-center gap-2">
        <span
          role="img"
          aria-label={awaiting ? t('status.awaiting_install') : online ? t('hardware.online') : t('hardware.offline')}
          className={cn('size-2.5 shrink-0 rounded-full', awaiting ? 'bg-[var(--awaiting)]' : online ? 'bg-[var(--healthy)]' : 'bg-[var(--urgent)]')}
        />
        <h2 className="m-0 me-auto text-[17px] font-medium">{node.name}</h2>
        <Chip tone={node.source === 'real' ? 'lime' : 'muted'}>{t(`source.${node.source}`)}</Chip>
        {awaiting && <Chip tone="muted">{t('status.awaiting_install')}</Chip>}
        {bench && <Chip tone="muted">{t('hardware.bench')}</Chip>}
      </header>

      {node.last_rejected_at && (
        <div role="alert" className="rounded-xl border border-[var(--urgent)] bg-[color-mix(in_srgb,var(--urgent)_12%,transparent)] p-2.5 text-sm">
          <p className="m-0 font-medium">
            {t('hardware.rejectedWarning', { reason: t(`hardware.rejectedReason.${node.last_rejected_reason ?? 'detached'}`) })}
          </p>
          <p className="m-0 text-xs text-text-2">
            {t('hardware.rejectedDetail', { at: formatDateTime(node.last_rejected_at), count: formatNumber(node.rejected_count ?? 0, 0) })}
          </p>
        </div>
      )}

      <dl className="m-0 flex flex-col gap-1.5">
        <Row label={t('hardware.type')}>
          {t(`hardware.types.${node.type}`)}
          {node.has_pump && ` + ${t('hardware.types.pump')}`}
        </Row>
        {node.park && <Row label={t('hardware.park')}>{i18n.language === 'ar' ? node.park.name_ar : node.park.name_en}</Row>}
        {awaiting ? (
          <p className="m-0 text-sm text-text-2">{t('hardware.awaitingLine')}</p>
        ) : (
          <>
            <Row label={t('hardware.lastPost')}>
              <span className={cn('tabular-nums', !online && 'text-[var(--urgent)]')}>{seen != null ? t('hardware.secondsAgo', { s: seen }) : t('common.never')}</span>{' '}
              <span className="text-xs text-text-2">{t('hardware.expected', { s: node.expected_interval_s })}</span>
            </Row>
            {node.rssi != null && (
              <Row label={t('hardware.rssi')}>
                <span dir="ltr" className="tabular-nums">
                  {node.rssi} dBm
                </span>{' '}
                <span className="text-xs text-text-2">{quality && t(`hardware.signal.${quality}`)}</span>
              </Row>
            )}
            {node.uptime_s != null && <Row label={t('hardware.uptime')}>{formatDuration(node.uptime_s)}</Row>}
            {node.fw_version && (
              <Row label={t('hardware.fw')}>
                <span dir="ltr">{node.fw_version}</span>
              </Row>
            )}
            {node.via && <Row label={t('hardware.connection')}>{t(`hardware.via.${node.via}`)}</Row>}
          </>
        )}
      </dl>

      {node.type === 'sensor' && !awaiting && (
        <Disclosure title={t('hardware.lastPayload')} meta={node.last_reading ? formatDateTime(node.last_reading.at) : undefined}>
          {node.last_reading ? (
            <pre dir="ltr" className="m-0 overflow-x-auto rounded-xl border border-[var(--glass-border)] bg-black/20 p-2.5 text-start text-xs">
              {JSON.stringify(node.last_reading, null, 2)}
            </pre>
          ) : (
            <p className="m-0 text-sm text-text-2">{t('hardware.noPayload')}</p>
          )}
        </Disclosure>
      )}
      {node.type === 'sensor' && !awaiting && (
        <Disclosure title={t('hardware.calibration.title')} className={PANEL}>
          <CalibrationPanel node={node} />
        </Disclosure>
      )}
      {node.has_pump && (
        <Disclosure title={t('hardware.relay.title')} className={PANEL}>
          <RelayPanel node={node} />
        </Disclosure>
      )}
      {node.type === 'camera' && (
        <Disclosure title={t('camera.title')} defaultOpen className={PANEL}>
          <CameraPanel node={node} />
        </Disclosure>
      )}

      <Disclosure title={t('hardware.rejections.title')} meta={rej.length > 0 ? formatNumber(rej.length, 0) : undefined}>
        {rej.length > 0 ? (
          <ul className="m-0 flex list-none flex-col gap-1 p-0 text-xs">
            {rej.map((r, i) => (
              <li key={i} className="flex flex-wrap gap-x-2 rounded-lg border border-[var(--glass-border)] px-2 py-1">
                <span className="text-text-2">{formatDateTime(r.at)}</span>
                <span className="font-medium tabular-nums">{r.status}</span>
                <span className="min-w-0 break-words">{r.message ?? ''}</span>
              </li>
            ))}
          </ul>
        ) : (
          <p className="m-0 text-sm text-text-2">{t('hardware.rejections.none')}</p>
        )}
      </Disclosure>

      <div className="flex flex-wrap gap-2">
        <TokenButton node={node} />
      </div>
    </GlassCard>
  )
}

/** /admin/hardware (Tools menu): one card per node of the company (real, simulated, bench). */
export default function HardwarePage() {
  const { t } = useTranslation()
  const [includeTest, setIncludeTest] = useState(loadIncludeTest)
  const nodes = useHardwareNodes(includeTest)
  const rejections = useDeviceRejections()
  const now = useNow()
  const rejectedNodes = nodes.data?.filter((n) => n.last_rejected_at).length ?? 0

  function toggle(on: boolean) {
    setIncludeTest(on)
    saveIncludeTest(on)
  }

  return (
    <div className="flex min-w-0 flex-col gap-5 pb-8">
      <section className="flex flex-wrap items-end justify-between gap-4">
        <div className="flex flex-col gap-1.5">
          <h1 className="m-0 text-[40px] leading-[1.05] font-light tracking-[-.01em]">{t('hardware.title')}</h1>
          <span className="text-sm text-text-2">{t('hardware.intro')}</span>
        </div>
        <label className="flex items-center gap-2.5 text-sm">
          <Toggle checked={includeTest} onChange={toggle} label={t('hardware.showBench')} />
          {t('hardware.showBench')}
        </label>
      </section>

      {rejectedNodes > 0 && (
        <div role="alert" className="rounded-xl border border-[var(--urgent)] bg-[color-mix(in_srgb,var(--urgent)_12%,transparent)] px-4 py-2.5 text-sm font-medium">
          {t('hardware.rejectedBanner', { count: rejectedNodes })}
        </div>
      )}
      {nodes.isPending && (
        <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3" aria-busy="true">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} h={320} radius={26} />
          ))}
        </div>
      )}
      {nodes.isError && (
        <EmptyState
          icon={<Cpu />}
          title={t('common.error')}
          action={
            <button type="button" className="h-10 rounded-full border border-[var(--glass-border-strong)] px-5 text-sm" onClick={() => nodes.refetch()}>
              {t('common.retry')}
            </button>
          }
        />
      )}
      {nodes.data?.length === 0 && <EmptyState icon={<Cpu />} title={t('hardware.empty')} />}
      {nodes.data && nodes.data.length > 0 && (
        <div className="grid items-start gap-5 md:grid-cols-2 xl:grid-cols-3">
          {nodes.data.map((node) => (
            <NodeCard key={node.id} node={node} now={now} rejections={rejections.data?.find((r) => r.node_id === node.id)} />
          ))}
        </div>
      )}
    </div>
  )
}

import { Cpu } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { CameraPanel } from '@/components/hardware/CameraPanel'
import { CalibrationPanel, RelayPanel } from '@/components/hardware/SensorPanels'
import { TokenButton } from '@/components/hardware/TokenDialog'
import { SourceBadge } from '@/components/StatusPill'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import { loadIncludeTest, saveIncludeTest, useDeviceRejections, useHardwareNodes } from '@/hooks/useHardware'
import { useNow } from '@/hooks/useNow'
import { formatDateTime, formatDuration } from '@/lib/format'
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
    <div className="flex items-baseline justify-between gap-3 text-sm">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="text-end">{children}</dd>
    </div>
  )
}

function NodeCard({ node, rejections, now }: { node: HardwareNode; rejections: DeviceRejections | undefined; now: number }) {
  const { t, i18n } = useTranslation()
  // Ticks client-side from the last post; the server value is only the fallback before the first tick.
  const seen = node.last_seen_at ? Math.max(0, Math.round((now - new Date(node.last_seen_at).getTime()) / 1000)) : null
  const online = seen != null && seen <= node.offline_after_s
  const quality = signal(node.rssi)
  const bench = node.park?.is_test === true

  return (
    <Card>
      <CardHeader className="gap-2">
        <CardTitle className="flex flex-wrap items-center gap-2">
          <span
            role="img"
            aria-label={online ? t('hardware.online') : t('hardware.offline')}
            className={cn('size-2.5 shrink-0 rounded-full', online ? 'bg-status-ok' : 'bg-status-offline')}
          />
          {node.name}
          <SourceBadge source={node.source} />
          {bench && <span className="rounded-md border border-dashed px-1.5 py-0.5 text-[0.7rem] font-medium">{t('hardware.bench')}</span>}
        </CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <dl className="flex flex-col gap-1">
          <Row label={t('hardware.type')}>
            {t(`hardware.types.${node.type}`)}
            {node.has_pump && ` + ${t('hardware.types.pump')}`}
          </Row>
          <Row label={t('hardware.park')}>{node.park ? (i18n.language === 'ar' ? node.park.name_ar : node.park.name_en) : '—'}</Row>
          <Row label={t('hardware.lastPost')}>
            <span className={cn('tabular-nums', !online && 'text-destructive')}>
              {seen != null ? t('hardware.secondsAgo', { s: seen }) : t('common.never')}
            </span>{' '}
            <span className="text-xs text-muted-foreground">{t('hardware.expected', { s: node.expected_interval_s })}</span>
          </Row>
          <Row label={t('hardware.rssi')}>
            {node.rssi != null ? (
              <>
                <span dir="ltr" className="tabular-nums">{node.rssi} dBm</span> <span className="text-xs text-muted-foreground">{quality && t(`hardware.signal.${quality}`)}</span>
              </>
            ) : (
              '—'
            )}
          </Row>
          <Row label={t('hardware.uptime')}>{node.uptime_s != null ? formatDuration(node.uptime_s) : '—'}</Row>
          <Row label={t('hardware.fw')}>
            <span dir="ltr">{node.fw_version ?? '—'}</span>
          </Row>
          <Row label={t('hardware.connection')}>{node.via ? t(`hardware.via.${node.via}`) : '—'}</Row>
        </dl>

        {node.type === 'sensor' && (
          <section className="flex flex-col gap-1.5">
            <h3 className="text-sm font-medium">{t('hardware.lastPayload')}</h3>
            {node.last_reading ? (
              <>
                <pre dir="ltr" className="overflow-x-auto rounded-md border bg-muted p-2 text-start text-xs">
                  {JSON.stringify(node.last_reading, null, 2)}
                </pre>
                <p className="text-xs text-muted-foreground">{formatDateTime(node.last_reading.at)}</p>
              </>
            ) : (
              <p className="text-sm text-muted-foreground">{t('hardware.noPayload')}</p>
            )}
          </section>
        )}

        {node.type === 'sensor' && <CalibrationPanel node={node} />}
        {node.has_pump && <RelayPanel node={node} />}
        {node.type === 'camera' && <CameraPanel node={node} />}

        <section className="flex flex-col gap-1.5">
          <h3 className="text-sm font-medium">{t('hardware.rejections.title')}</h3>
          {rejections && rejections.requests.length > 0 ? (
            <ul className="flex flex-col gap-1 text-xs">
              {rejections.requests.slice(0, 5).map((r, i) => (
                <li key={i} className="flex flex-wrap gap-x-2 rounded border px-2 py-1">
                  <span className="text-muted-foreground">{formatDateTime(r.at)}</span>
                  <span className="font-mono font-medium">{r.status}</span>
                  <span className="min-w-0 break-words">{r.message ?? ''}</span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-muted-foreground">{t('hardware.rejections.none')}</p>
          )}
        </section>

        <div className="flex flex-wrap gap-2 border-t pt-3">
          <TokenButton node={node} />
        </div>
      </CardContent>
    </Card>
  )
}

/** /admin/hardware: one card per node of the company (real, simulated, bench). */
export default function HardwarePage() {
  const { t } = useTranslation()
  const [includeTest, setIncludeTest] = useState(loadIncludeTest)
  const nodes = useHardwareNodes(includeTest)
  const rejections = useDeviceRejections()
  const now = useNow()

  function toggle(on: boolean) {
    setIncludeTest(on)
    saveIncludeTest(on)
  }

  return (
    <div className="mx-auto flex max-w-6xl flex-col gap-4 p-4 lg:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-xl font-semibold">
            <Cpu className="size-5" />
            {t('hardware.title')}
          </h1>
          <p className="text-sm text-muted-foreground">{t('hardware.intro')}</p>
        </div>
        <label className="flex items-center gap-2 text-sm">
          <Switch checked={includeTest} onCheckedChange={toggle} aria-label={t('hardware.showBench')} />
          {t('hardware.showBench')}
        </label>
      </div>

      {nodes.isPending && (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-80" />
          ))}
        </div>
      )}
      {nodes.isError && (
        <div role="alert" className="flex items-center gap-3 text-sm text-destructive">
          {t('common.error')}
          <Button variant="outline" size="sm" onClick={() => nodes.refetch()}>
            {t('common.retry')}
          </Button>
        </div>
      )}
      {nodes.data?.length === 0 && <p className="py-10 text-center text-sm text-muted-foreground">{t('hardware.empty')}</p>}
      {nodes.data && nodes.data.length > 0 && (
        <div className="grid items-start gap-4 md:grid-cols-2 xl:grid-cols-3">
          {nodes.data.map((node) => (
            <NodeCard key={node.id} node={node} now={now} rejections={rejections.data?.find((r) => r.node_id === node.id)} />
          ))}
        </div>
      )}
    </div>
  )
}

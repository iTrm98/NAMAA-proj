import { Power } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { Button } from '@/components/ui/button'
import { useCalibrate, useRelayTest } from '@/hooks/useHardware'
import { ApiError } from '@/lib/api'
import { formatNumber } from '@/lib/format'
import type { HardwareNode } from '@/types'

function errorText(error: unknown, fallback: string): string {
  if (error instanceof ApiError && error.body) {
    const first = error.body.errors ? Object.values(error.body.errors)[0]?.[0] : undefined
    return first ?? error.body.message ?? fallback
  }
  return fallback
}

/** Live raw soil value, "Use as dry" / "Use as wet" (confirmed), and the % the current calibration gives. */
export function CalibrationPanel({ node }: { node: HardwareNode }) {
  const { t } = useTranslation()
  const calibrate = useCalibrate(node.id)
  const cal = node.calibration
  if (!cal) return null

  const raw = cal.latest_raw
  const span = cal.dry_value - cal.wet_value
  const preview = raw != null && span > 0 ? Math.min(100, Math.max(0, ((cal.dry_value - raw) / span) * 100)) : null
  const usable = raw != null && node.online

  function save(point: 'dry' | 'wet') {
    calibrate.mutate(point, {
      onSuccess: () => toast.success(t(`hardware.calibration.saved_${point}`)),
      onError: (error) => toast.error(errorText(error, t('common.error'))),
    })
  }

  return (
    <section className="flex flex-col gap-2 rounded-lg border p-3">
      <h3 className="text-sm font-medium">{t('hardware.calibration.title')}</h3>
      <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
        <dt className="text-muted-foreground">{t('hardware.calibration.raw')}</dt>
        <dd className="font-mono tabular-nums">{raw ?? '—'}</dd>
        <dt className="text-muted-foreground">{t('hardware.calibration.preview')}</dt>
        <dd className="font-mono tabular-nums">{preview != null ? `${formatNumber(preview)} %` : '—'}</dd>
        <dt className="text-muted-foreground">{t('hardware.calibration.dry')}</dt>
        <dd className="font-mono tabular-nums">{cal.dry_value}</dd>
        <dt className="text-muted-foreground">{t('hardware.calibration.wet')}</dt>
        <dd className="font-mono tabular-nums">{cal.wet_value}</dd>
      </dl>
      <div className="flex flex-wrap gap-2">
        {(['dry', 'wet'] as const).map((point) => (
          <ConfirmDialog
            key={point}
            trigger={
              <Button variant="outline" size="sm" disabled={!usable || calibrate.isPending}>
                {t(`hardware.calibration.use_${point}`)}
              </Button>
            }
            title={t(`hardware.calibration.confirm_${point}_title`)}
            body={t(`hardware.calibration.confirm_${point}_body`, { raw: raw ?? '—' })}
            onConfirm={() => save(point)}
          />
        ))}
      </div>
      {!usable && <p className="text-xs text-muted-foreground">{t('hardware.calibration.needLive')}</p>}
    </section>
  )
}

/** "Test relay 10 s": a bench node switches at once after one confirmation; a real node asks twice and sends confirm:true. */
export function RelayPanel({ node }: { node: HardwareNode }) {
  const { t } = useTranslation()
  const relay = useRelayTest(node.id)
  const [second, setSecond] = useState(false)
  const [now, setNow] = useState(() => Date.now())
  const bench = node.park?.is_test === true
  const until = node.pump?.test_until ? new Date(node.pump.test_until).getTime() : null
  const remaining = until ? Math.max(0, Math.ceil((until - now) / 1000)) : 0

  useEffect(() => {
    if (!until) return
    const id = window.setInterval(() => setNow(Date.now()), 500)
    return () => window.clearInterval(id)
  }, [until])

  function run(confirm: boolean) {
    relay.mutate(confirm, {
      onSuccess: (r) => toast.success(t('hardware.relay.started', { s: r.seconds })),
      onError: (error) => toast.error(errorText(error, t('common.error'))),
    })
  }

  const disabled = relay.isPending || remaining > 0 || node.source === 'simulated' || node.pump?.target === true
  const button = (
    <Button variant="outline" size="sm" disabled={disabled}>
      <Power />
      {remaining > 0 ? t('hardware.relay.running', { s: remaining }) : t('hardware.relay.button')}
    </Button>
  )

  return (
    <section className="flex flex-col gap-2 rounded-lg border p-3">
      <h3 className="text-sm font-medium">{t('hardware.relay.title')}</h3>
      <p className="text-xs text-muted-foreground">{bench ? t('hardware.relay.benchHint') : t('hardware.relay.realHint')}</p>
      <div>
        {bench ? (
          <ConfirmDialog trigger={button} title={t('hardware.relay.confirmTitle', { name: node.name })} body={t('hardware.relay.confirmBench')} onConfirm={() => run(false)} />
        ) : (
          <>
            <ConfirmDialog trigger={button} title={t('hardware.relay.confirmTitle', { name: node.name })} body={t('hardware.relay.confirmReal')} onConfirm={() => setSecond(true)} />
            <AlertDialog open={second} onOpenChange={setSecond}>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>{t('hardware.relay.secondTitle')}</AlertDialogTitle>
                  <AlertDialogDescription>{t('hardware.relay.secondBody', { name: node.name })}</AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>{t('common.cancel')}</AlertDialogCancel>
                  <AlertDialogAction variant="destructive" onClick={() => run(true)}>
                    {t('hardware.relay.secondConfirm')}
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          </>
        )}
      </div>
    </section>
  )
}

import { CheckCircle2, XCircle } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Skeleton } from '@/components/ds'
import { useReadiness } from '@/hooks/useStand'
import { cn } from '@/lib/utils'

/**
 * The pre-demo checklist: one row per part (dot, name, one line of detail, and one line of what to do when it is red).
 * Refreshed every 10 s. Optional parts that are red are shown quietly: they do not block "ready".
 */
export function ReadinessPanel() {
  const { t } = useTranslation()
  const readiness = useReadiness()
  const data = readiness.data

  if (readiness.isPending) return <Skeleton className="h-64 w-full rounded-2xl" />
  if (readiness.isError || !data)
    return (
      <p role="alert" className="m-0 text-sm text-destructive">
        {t('common.error')}{' '}
        <button type="button" className="underline" onClick={() => void readiness.refetch()}>
          {t('common.retry')}
        </button>
      </p>
    )

  return (
    <ul className="m-0 flex list-none flex-col divide-y divide-[var(--glass-border)] p-0">
      {data.checks.map((c) => (
        <li key={c.key} className="flex items-start gap-3 py-3">
          {c.ok ? (
            <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-[var(--healthy)]" aria-label={t('connect.ok')} />
          ) : c.required ? (
            <XCircle className="mt-0.5 size-5 shrink-0 text-[var(--urgent)]" aria-label={t('connect.notOk')} />
          ) : (
            <span className="mt-1 size-4 shrink-0 rounded-full border-[1.5px] border-[var(--awaiting)]" aria-label={t('connect.optional')} />
          )}
          <span className="flex min-w-0 flex-col gap-0.5">
            <span className="text-[15px] font-medium">{t(`connect.check.${c.key}`, { defaultValue: c.key })}</span>
            <span className={cn('text-[13px]', c.ok ? 'text-text-2' : 'text-[var(--text)]')}>{c.ok ? t(`connect.okLine.${c.key}`, { defaultValue: '' }) : t(`connect.fix.${c.key}`, { defaultValue: t('connect.fixGeneric') })}</span>
          </span>
        </li>
      ))}
    </ul>
  )
}

import { useTranslation } from 'react-i18next'
import { Skeleton } from '@/components/ds'
import { useWorkers } from '@/hooks/useOps'
import { personName } from '@/lib/names'
import { cn } from '@/lib/utils'

/** A radio list of workers (with their open task count). `value` is the worker id, or null before one is picked. */
export function WorkerPicker({ value, onChange }: { value: number | null; onChange: (id: number) => void }) {
  const { t, i18n } = useTranslation()
  const workers = useWorkers()

  if (workers.isPending) return <Skeleton h={120} radius={14} />
  if (workers.isError)
    return (
      <p role="alert" className="m-0 text-sm text-destructive">
        {t('common.error')}{' '}
        <button type="button" className="underline" onClick={() => void workers.refetch()}>
          {t('common.retry')}
        </button>
      </p>
    )
  if (workers.data.length === 0) return <p className="m-0 text-sm text-text-2">{t('workerPicker.none')}</p>

  return (
    <div role="radiogroup" aria-label={t('workerPicker.label')} className="flex max-h-64 flex-col gap-1.5 overflow-y-auto">
      {workers.data.map((w) => {
        const selected = value === w.id
        const load = w.open_tasks + w.in_progress_tasks
        return (
          <button
            key={w.id}
            type="button"
            role="radio"
            aria-checked={selected}
            onClick={() => onChange(w.id)}
            className={cn(
              'flex min-h-12 w-full cursor-pointer items-center justify-between gap-3 rounded-xl border px-4 text-start text-sm transition-colors',
              selected ? 'border-[var(--accent-lime)] bg-white/10' : 'border-[var(--glass-border)] hover:bg-white/[.06]',
            )}
          >
            <span className="font-medium">{personName(w, i18n.language)}</span>
            <span className="text-xs text-text-2">{t('workerPicker.load', { count: load })}</span>
          </button>
        )
      })}
    </div>
  )
}

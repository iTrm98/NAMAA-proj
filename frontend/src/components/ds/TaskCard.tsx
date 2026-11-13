import { useTranslation } from 'react-i18next'
import { parkName } from '@/hooks/useParks'
import { taskTitle } from '@/lib/names'
import { cn } from '@/lib/utils'
import type { Task, TaskPriority } from '@/types'
import { Chip, type ChipTone } from './Chip'

const PRIORITY_TONE: Record<TaskPriority, ChipTone> = { urgent: 'urgent', high: 'high', normal: 'normal', low: 'muted' }

export interface TaskCardProps {
  task: Task
  compact?: boolean
  /** 0-100 progress (e.g. verification); the bar is hidden when undefined. */
  progress?: number
  onClick?: () => void
  className?: string
  /** Kanban mode: category chip instead of the status chip, assignee beside the park, a result line. */
  category?: string
  assignee?: string | null
  hideStatus?: boolean
  result?: { text: string; tone: 'ok' | 'bad' | 'muted' | 'warn' }
}

const RESULT_COLOR = { ok: 'var(--healthy)', bad: 'var(--urgent)', muted: 'var(--text-muted)', warn: 'var(--water)' } as const

/** Kanban / list task card: priority and status chips, title, park, progress. */
export function TaskCard({ task, compact, progress, onClick, className, category, assignee, hideStatus, result }: TaskCardProps) {
  const { t, i18n } = useTranslation()
  const Tag = onClick ? 'button' : 'article'
  return (
    <Tag
      type={onClick ? 'button' : undefined}
      onClick={onClick}
      className={cn('flex w-full flex-col gap-2 rounded-[var(--r-mini)] border border-[var(--glass-border)] bg-[var(--glass-bg-strong)] p-3.5 text-start', onClick && 'cursor-pointer hover:bg-white/10', className)}
    >
      <div className="flex flex-wrap gap-1.5">
        <Chip tone={PRIORITY_TONE[task.priority]}>{t(`priority.${task.priority}`)}</Chip>
        {category && <Chip tone="muted">{category}</Chip>}
        {!hideStatus && <Chip tone="muted">{t(`taskStatus.${task.status}`)}</Chip>}
        {task.park?.site_type === 'demo_stand' && <Chip tone="muted">{t('taskCard.stand')}</Chip>}
        {task.escalated_at && <Chip tone="urgent">{t('taskCard.escalated')}</Chip>}
        {task.overdue && <Chip tone="high">{t('taskCard.overdue')}</Chip>}
      </div>
      <h3 className={cn('m-0 font-medium leading-snug', compact ? 'text-[13px]' : 'text-[15px]')}>{taskTitle(t, i18n.language, task)}</h3>
      {!compact && (task.park || assignee) && (
        <p className="m-0 text-xs text-text-2">{[task.park ? parkName(task.park, i18n.language) : null, assignee].filter(Boolean).join(' · ')}</p>
      )}
      {progress !== undefined && (
        <div className="h-1.5 overflow-hidden rounded-full bg-white/10" role="progressbar" aria-valuenow={Math.round(progress)} aria-valuemin={0} aria-valuemax={100}>
          <div className="h-full origin-left rounded-full bg-[var(--accent-lime)] rtl:origin-right" style={{ transform: `scaleX(${Math.min(100, Math.max(0, progress)) / 100})`, transition: 'transform .3s ease-out' }} />
        </div>
      )}
      {result && (
        <span className="text-xs" style={{ color: RESULT_COLOR[result.tone] }}>
          {result.text}
        </span>
      )}
    </Tag>
  )
}

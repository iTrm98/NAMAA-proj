import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'
import { Chip, type ChipProps } from './Chip'

export interface MiniCardProps {
  chips?: Pick<ChipProps, 'tone' | 'children'>[]
  title: string
  meta?: string
  /** A big figure on the end side, e.g. "18%". */
  figure?: string
  /** 0-100 progress bar. */
  progress?: number
  action?: ReactNode
  variant?: 'default' | 'urgent' | 'setup'
  className?: string
}

const VARIANTS = {
  default: 'border-[var(--glass-border)]',
  urgent: 'border-[rgba(255,107,94,.45)]',
  setup: 'border-dashed border-[var(--glass-border-strong)]',
} as const

/** "Needs attention" card: chips, title, meta, optional figure and progress, one action. */
export function MiniCard({ chips, title, meta, figure, progress, action, variant = 'default', className }: MiniCardProps) {
  return (
    <article className={cn('flex flex-col gap-2 rounded-[var(--r-mini)] border bg-[var(--glass-bg-strong)] p-3.5', VARIANTS[variant], className)}>
      {chips && chips.length > 0 && (
        <div className="flex flex-wrap gap-1.5">
          {chips.map((c, i) => (
            <Chip key={i} tone={c.tone}>
              {c.children}
            </Chip>
          ))}
        </div>
      )}
      <div className="flex items-start justify-between gap-2">
        <h3 className="m-0 text-[15px] font-medium leading-snug">{title}</h3>
        {figure && <span className="text-lg font-medium tabular-nums">{figure}</span>}
      </div>
      {meta && <p className="m-0 text-xs text-text-2">{meta}</p>}
      {progress !== undefined && (
        <div className="h-2 overflow-hidden rounded-full bg-white/[.08]" role="progressbar" aria-valuenow={Math.round(progress)} aria-valuemin={0} aria-valuemax={100}>
          <div className="h-full origin-left rounded-full bg-[var(--accent-lime)] rtl:origin-right" style={{ transform: `scaleX(${Math.min(100, Math.max(0, progress)) / 100})`, transition: 'transform .3s ease-out' }} />
        </div>
      )}
      {action}
    </article>
  )
}

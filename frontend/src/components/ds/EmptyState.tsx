import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

export interface EmptyStateProps {
  icon?: ReactNode
  title: string
  hint?: string
  action?: ReactNode
  className?: string
}

/** Empty (and error) block: round outlined icon, title, hint, optional action. */
export function EmptyState({ icon, title, hint, action, className }: EmptyStateProps) {
  return (
    <div className={cn('flex flex-col items-center gap-3 px-4 py-10 text-center', className)} role="status">
      {icon && (
        <span className="flex size-11 items-center justify-center rounded-full border border-[var(--glass-border-strong)] text-[var(--text-muted)] [&>svg]:size-[18px]">
          {icon}
        </span>
      )}
      <h2 className="m-0 text-[15px] font-medium">{title}</h2>
      {hint && <p className="m-0 max-w-sm text-xs text-text-2">{hint}</p>}
      {action}
    </div>
  )
}

import { motion } from 'motion/react'
import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

export interface RailItem {
  icon: ReactNode
  /** Required: used as aria-label and tooltip. */
  label: string
  onClick: () => void
  active?: boolean
}

export interface RailProps {
  items: RailItem[]
  ariaLabel: string
  className?: string
}

/** Vertical quick-action rail: 44 px round icon buttons in a glass capsule. */
export function Rail({ items, ariaLabel, className }: RailProps) {
  return (
    <aside aria-label={ariaLabel} className={cn('flex flex-none flex-col gap-2 rounded-full border border-[var(--glass-border)] bg-[var(--glass-bg)] p-2', className)}>
      {items.map((item) => (
        <motion.button
          key={item.label}
          type="button"
          aria-label={item.label}
          title={item.label}
          aria-pressed={item.active}
          onClick={item.onClick}
          whileTap={{ scale: 0.97 }}
          transition={{ duration: 0.15 }}
          className={cn(
            'flex size-11 items-center justify-center rounded-full text-[var(--text-muted)] transition-colors hover:bg-white/10 hover:text-white [&>svg]:size-[18px]',
            item.active && 'bg-[var(--pill-active-bg)] text-white',
          )}
        >
          {item.icon}
        </motion.button>
      ))}
    </aside>
  )
}

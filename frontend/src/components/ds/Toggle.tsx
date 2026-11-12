import { motion } from 'motion/react'
import { cn } from '@/lib/utils'
import { useDir } from './useDir'

export interface ToggleProps {
  checked: boolean
  onChange: (checked: boolean) => void
  /** Accessible name (the visible label is rendered by the caller). */
  label: string
  disabled?: boolean
}

/** 44x26 switch, knob 20; the knob mirrors in RTL. */
export function Toggle({ checked, onChange, label, disabled }: ToggleProps) {
  const dir = useDir()
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label}
      disabled={disabled}
      onClick={() => onChange(!checked)}
      className={cn('relative h-[26px] w-11 shrink-0 rounded-full transition-colors disabled:opacity-50', checked ? 'bg-[var(--accent-lime)]' : 'bg-white/[.16]')}
    >
      <motion.span
        className={cn('absolute top-[3px] size-5 rounded-full', checked ? 'bg-[var(--on-accent)]' : 'bg-[var(--text)]')}
        style={{ insetInlineStart: 3 }}
        animate={{ x: checked ? 18 * dir : 0 }}
        transition={{ duration: 0.15, ease: 'easeOut' }}
      />
    </button>
  )
}

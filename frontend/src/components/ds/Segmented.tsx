import { motion } from 'motion/react'
import { useId } from 'react'
import { cn } from '@/lib/utils'

export interface SegmentedOption<V extends string = string> {
  value: V
  label: string
}

export interface SegmentedProps<V extends string = string> {
  options: SegmentedOption<V>[]
  value: V
  onChange: (value: V) => void
  ariaLabel: string
  className?: string
}

/** Segmented control with a sliding pill (1H/24H/7D, Grid/Table, Status/Vegetation, park tabs). */
export function Segmented<V extends string = string>({ options, value, onChange, ariaLabel, className }: SegmentedProps<V>) {
  const id = useId()
  return (
    <div
      role="radiogroup"
      aria-label={ariaLabel}
      className={cn('inline-flex gap-[3px] rounded-full border border-[var(--glass-border)] bg-[var(--pill-track-bg)] p-1', className)}
    >
      {options.map((o) => {
        const on = o.value === value
        return (
          <button
            key={o.value}
            type="button"
            role="radio"
            aria-checked={on}
            onClick={() => onChange(o.value)}
            className={cn('relative min-h-8 rounded-full px-3.5 py-1.5 text-[13px] transition-colors', on ? 'text-white' : 'text-[var(--text-muted)] hover:text-white')}
          >
            {on && (
              <motion.span
                layoutId={`${id}-pill`}
                className="absolute inset-0 rounded-full bg-[var(--pill-active-bg)]"
                transition={{ type: 'spring', stiffness: 500, damping: 38 }}
              />
            )}
            <span className="relative">{o.label}</span>
          </button>
        )
      })}
    </div>
  )
}

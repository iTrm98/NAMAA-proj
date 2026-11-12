import NumberFlow from '@number-flow/react'
import { useReducedMotion } from 'motion/react'
import { useEffect, useState, type ReactNode } from 'react'
import { Chip, type ChipProps } from './Chip'

export interface KpiProps {
  icon: ReactNode
  value: number | string
  label: string
  /** Fraction digits when `value` is a number (default 0). */
  digits?: number
  /** Text after the number, e.g. "%". */
  suffix?: string
  badge?: Pick<ChipProps, 'tone' | 'children'>
}

/** 44 px outlined icon circle + 26 px rolling number (Latin digits in both languages) + label. */
export function Kpi({ icon, value, label, digits = 0, suffix, badge }: KpiProps) {
  const reduce = useReducedMotion()
  // Mount at 0, then roll to the value (count-up on first load, smooth on live changes).
  const [shown, setShown] = useState<number | string>(typeof value === 'number' ? 0 : value)
  useEffect(() => setShown(value), [value])

  return (
    <div className="flex items-center gap-3">
      <span className="flex size-11 shrink-0 items-center justify-center rounded-full border border-[var(--glass-border-strong)] text-[var(--text-muted)] [&>svg]:size-[18px]">
        {icon}
      </span>
      <div>
        <div className="flex items-center gap-2">
          <b className="text-[26px] font-medium leading-tight tabular-nums">
            {typeof shown === 'number' ? (
              <NumberFlow
                value={shown}
                locales="en-US"
                suffix={suffix}
                format={{ minimumFractionDigits: digits, maximumFractionDigits: digits }}
                animated={!reduce}
                transformTiming={{ duration: 300, easing: 'ease-out' }}
              />
            ) : (
              shown
            )}
          </b>
          {badge && <Chip tone={badge.tone}>{badge.children}</Chip>}
        </div>
        <span className="text-xs text-text-2">{label}</span>
      </div>
    </div>
  )
}

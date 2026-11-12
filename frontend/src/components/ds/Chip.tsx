import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

export type ChipTone = 'urgent' | 'high' | 'normal' | 'muted' | 'ok' | 'lime'

const TONES: Record<ChipTone, string> = {
  urgent: 'bg-[var(--chip-urgent-bg)] text-[var(--chip-urgent-fg)]',
  high: 'bg-[var(--chip-high-bg)] text-[var(--chip-high-fg)]',
  normal: 'bg-[var(--chip-normal-bg)] text-[var(--chip-normal-fg)]',
  muted: 'bg-[var(--chip-muted-bg)] text-[var(--chip-muted-fg)]',
  ok: 'bg-[var(--chip-ok-bg)] text-[var(--chip-ok-fg)]',
  lime: 'bg-[var(--chip-lime-bg)] text-[var(--chip-lime-fg)]',
}

export interface ChipProps {
  tone?: ChipTone
  children: ReactNode
  className?: string
}

/** Soft pill for priority, status, category and kind (Simulated / Real). */
export function Chip({ tone = 'muted', children, className }: ChipProps) {
  return (
    <span className={cn('inline-flex items-center rounded-full px-[9px] py-[3px] text-[11px] font-medium leading-tight', TONES[tone], className)}>
      {children}
    </span>
  )
}

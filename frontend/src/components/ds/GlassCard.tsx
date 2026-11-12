import type { ElementType, HTMLAttributes, ReactNode } from 'react'
import { cn } from '@/lib/utils'

export interface GlassCardProps extends Omit<HTMLAttributes<HTMLElement>, 'children'> {
  as?: ElementType
  /** Inner padding in px (default 20). */
  padding?: number
  /** No blur: use for anything floating over the live map. */
  solid?: boolean
  /** Corner radius: card 26 (default), sm 24 (Parks cards, columns), worker 22, mini 18. */
  radius?: 'card' | 'sm' | 'worker' | 'mini'
  children?: ReactNode
}

const RADII = { card: 'var(--r-card)', sm: 'var(--r-card-sm)', worker: 'var(--r-worker)', mini: 'var(--r-mini)' } as const

/** The glass surface of the design: translucent fill, 1px border, blur (none when `solid`). */
export function GlassCard({ as: Tag = 'div', padding = 20, solid, radius = 'card', className, style, children, ...rest }: GlassCardProps) {
  return (
    <Tag className={cn('glass', solid && 'solid', className)} style={{ padding, borderRadius: RADII[radius], ...style }} {...rest}>
      {children}
    </Tag>
  )
}

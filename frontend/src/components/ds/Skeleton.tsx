import { cn } from '@/lib/utils'

export interface SkeletonProps {
  w?: number | string
  h?: number | string
  /** Corner radius in px (default 12). */
  radius?: number | string
  className?: string
}

/** Loading placeholder; the shimmer is CSS, transform only, off under reduced motion. */
export function Skeleton({ w = '100%', h = 16, radius = 12, className }: SkeletonProps) {
  return <div aria-hidden="true" className={cn('skeleton', className)} style={{ width: w, height: h, borderRadius: radius }} />
}

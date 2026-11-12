import { ChevronDown } from 'lucide-react'
import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

export interface DisclosureProps {
  title: ReactNode
  /** Small text next to the title (counts, state). */
  meta?: ReactNode
  defaultOpen?: boolean
  className?: string
  children: ReactNode
}

/** Collapsible section for developer details (payloads, health checks). Native <details>: keyboard and RTL safe. */
export function Disclosure({ title, meta, defaultOpen, className, children }: DisclosureProps) {
  return (
    <details open={defaultOpen} className={cn('group rounded-2xl border border-[var(--glass-border)] bg-white/[.03]', className)}>
      <summary className="flex min-h-11 cursor-pointer list-none items-center gap-2 px-4 py-2.5 text-[13px] font-medium [&::-webkit-details-marker]:hidden">
        <span className="flex-1">{title}</span>
        {meta && <span className="text-xs font-normal text-text-2">{meta}</span>}
        <ChevronDown className="size-4 text-text-2 transition-transform group-open:rotate-180" />
      </summary>
      <div className="flex flex-col gap-3 px-4 pt-1 pb-4">{children}</div>
    </details>
  )
}

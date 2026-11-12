import { ChevronDown } from 'lucide-react'
import { motion } from 'motion/react'
import { DropdownMenu } from 'radix-ui'
import { useId } from 'react'
import { Link } from 'react-router'
import { cn } from '@/lib/utils'

export interface PillNavItem {
  /** Unique key and route. For a menu item it is the key only (the menu entries carry the routes). */
  to: string
  label: string
  /** Turns the item into a dropdown ("Tools"): each entry is a link. */
  menu?: { to: string; label: string }[]
}

export interface PillNavProps {
  items: PillNavItem[]
  /** `to` of the active item, or of one of a menu's entries (undefined: none is active). */
  activeTo?: string
  ariaLabel: string
  className?: string
}

const LINK = 'relative flex min-h-[38px] items-center gap-1.5 rounded-full px-[18px] py-[9px] text-sm no-underline transition-colors'

/** Pill navigation with a sliding active pill (5px padding, links 9x18) and optional dropdown items. */
export function PillNav({ items, activeTo, ariaLabel, className }: PillNavProps) {
  const id = useId()
  const pill = (
    <motion.span
      layoutId={`${id}-pill`}
      className="absolute inset-0 rounded-full bg-[var(--pill-active-bg)]"
      transition={{ type: 'spring', stiffness: 500, damping: 38 }}
    />
  )
  return (
    <nav
      aria-label={ariaLabel}
      className={cn('flex flex-wrap gap-1 rounded-full border border-[var(--glass-border)] bg-[var(--pill-track-bg)] p-[5px]', className)}
    >
      {items.map((item) => {
        const active = item.to === activeTo || !!item.menu?.some((m) => m.to === activeTo)
        const cls = cn(LINK, active ? 'font-medium text-white' : 'text-[var(--text-muted)] hover:text-white')
        if (item.menu) {
          return (
            <DropdownMenu.Root key={item.to}>
              <DropdownMenu.Trigger className={cn(cls, 'cursor-pointer')}>
                {active && pill}
                <span className="relative">{item.label}</span>
                <ChevronDown className="relative size-3.5" aria-hidden />
              </DropdownMenu.Trigger>
              <DropdownMenu.Portal>
                <DropdownMenu.Content
                  align="start"
                  sideOffset={8}
                  className="z-50 min-w-40 rounded-[var(--r-mini)] border border-[var(--glass-border-strong)] bg-popover p-1.5 shadow-xl"
                >
                  {item.menu.map((m) => (
                    <DropdownMenu.Item key={m.to} asChild>
                      <Link
                        to={m.to}
                        className={cn(
                          'flex min-h-10 items-center rounded-xl px-3.5 text-sm no-underline outline-none data-[highlighted]:bg-white/10',
                          m.to === activeTo ? 'bg-white/10 text-white' : 'text-[var(--text-muted)]',
                        )}
                      >
                        {m.label}
                      </Link>
                    </DropdownMenu.Item>
                  ))}
                </DropdownMenu.Content>
              </DropdownMenu.Portal>
            </DropdownMenu.Root>
          )
        }
        return (
          <Link key={item.to} to={item.to} aria-current={active ? 'page' : undefined} className={cls}>
            {active && pill}
            <span className="relative">{item.label}</span>
          </Link>
        )
      })}
    </nav>
  )
}

import { X } from 'lucide-react'
import { AnimatePresence, motion } from 'motion/react'
import { Dialog as DialogPrimitive } from 'radix-ui'
import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { useDir } from './useDir'

export interface DrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  description?: string
  children: ReactNode
  /** Panel width in px (default 420). */
  width?: number
}

/** Side panel that slides in from the inline-end edge (mirrored in RTL). */
export function Drawer({ open, onOpenChange, title, description, children, width = 420 }: DrawerProps) {
  const { t } = useTranslation()
  const dir = useDir()
  return (
    <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
      <AnimatePresence>
        {open && (
          <DialogPrimitive.Portal forceMount>
            <DialogPrimitive.Overlay asChild forceMount>
              <motion.div className="fixed inset-0 z-50 bg-black/55" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} transition={{ duration: 0.2 }} />
            </DialogPrimitive.Overlay>
            <DialogPrimitive.Content asChild forceMount aria-describedby={undefined}>
              <motion.div
                className="fixed inset-y-0 end-0 z-50 flex max-w-full flex-col gap-4 overflow-y-auto border-s border-[var(--glass-border)] bg-popover p-5 outline-none"
                style={{ width }}
                initial={{ opacity: 0, x: 40 * dir }}
                animate={{ opacity: 1, x: 0 }}
                exit={{ opacity: 0, x: 40 * dir }}
                transition={{ duration: 0.25, ease: 'easeOut' }}
              >
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <DialogPrimitive.Title className="m-0 text-[17px] font-medium">{title}</DialogPrimitive.Title>
                    {description && <DialogPrimitive.Description className="m-0 mt-1 text-xs text-text-2">{description}</DialogPrimitive.Description>}
                  </div>
                  <DialogPrimitive.Close
                    aria-label={t('common.close')}
                    className="flex size-11 shrink-0 items-center justify-center rounded-full border border-[var(--glass-border)] bg-white/[.06] text-[var(--text)]"
                  >
                    <X className="size-[18px]" />
                  </DialogPrimitive.Close>
                </div>
                {children}
              </motion.div>
            </DialogPrimitive.Content>
          </DialogPrimitive.Portal>
        )}
      </AnimatePresence>
    </DialogPrimitive.Root>
  )
}

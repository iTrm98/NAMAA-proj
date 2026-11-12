import { CircleCheck, Info, OctagonX, TriangleAlert } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Toaster as Sonner, toast } from 'sonner'

/**
 * The app toast host, styled with the design tokens and placed on the inline-end edge
 * (slides in from the end, mirrored in RTL). Call `toast.success(...)` etc. from anywhere.
 */
export function Toast() {
  const { i18n } = useTranslation()
  const rtl = i18n.dir() === 'rtl'
  return (
    <Sonner
      theme="dark"
      dir={rtl ? 'rtl' : 'ltr'}
      position={rtl ? 'bottom-left' : 'bottom-right'}
      closeButton
      icons={{
        success: <CircleCheck className="size-4 text-[var(--healthy)]" />,
        info: <Info className="size-4 text-[var(--info)]" />,
        warning: <TriangleAlert className="size-4 text-[var(--water)]" />,
        error: <OctagonX className="size-4 text-[var(--urgent)]" />,
      }}
      style={
        {
          '--normal-bg': 'var(--solid-over-map)',
          '--normal-text': 'var(--text)',
          '--normal-border': 'var(--glass-border-strong)',
          '--border-radius': 'var(--r-mini)',
          fontFamily: 'inherit',
        } as React.CSSProperties
      }
    />
  )
}

export { toast }

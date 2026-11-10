import { Copy, KeyRound } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { useRegenerateToken } from '@/hooks/useHardware'
import { ApiError } from '@/lib/api'
import { copyText } from '@/lib/clipboard'
import type { HardwareNode } from '@/types'

function CopyBlock({ label, text }: { label: string; text: string }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-col gap-1.5">
      <div className="flex items-center justify-between gap-2">
        <span className="text-sm font-medium">{label}</span>
        <Button
          type="button"
          size="sm"
          variant="outline"
          onClick={() =>
            void copyText(text).then((ok) => (ok ? toast.success(t('hardware.token.copied')) : toast.error(t('hardware.token.copyFailed'))))
          }
        >
          <Copy />
          {t('hardware.token.copy')}
        </Button>
      </div>
      <pre dir="ltr" className="overflow-x-auto rounded-md border bg-muted p-2 text-start text-xs break-all whitespace-pre-wrap">
        {text}
      </pre>
    </div>
  )
}

/**
 * "New token": warns that the board must be updated, asks the server for a new
 * token and shows it once. The token lives only in the mutation result and is
 * dropped (mutation.reset) the moment the dialog closes; it is never logged.
 */
export function TokenButton({ node }: { node: HardwareNode }) {
  const { t } = useTranslation()
  const regenerate = useRegenerateToken(node.id)
  const [open, setOpen] = useState(false)
  const token = regenerate.data

  function start() {
    regenerate.mutate(undefined, {
      onSuccess: () => setOpen(true),
      onError: (error) => toast.error(error instanceof ApiError && error.body?.message ? error.body.message : t('common.error')),
    })
  }

  function close() {
    setOpen(false)
    regenerate.reset()
  }

  return (
    <>
      <ConfirmDialog
        destructive
        trigger={
          <Button variant="outline" size="sm" disabled={regenerate.isPending || node.source === 'simulated' || node.camera?.capture_mode === 'browser'}>
            <KeyRound />
            {t('hardware.token.button')}
          </Button>
        }
        title={t('hardware.token.confirmTitle', { name: node.name })}
        body={t('hardware.token.confirmBody')}
        confirmLabel={t('hardware.token.confirm')}
        onConfirm={start}
      />
      <Dialog open={open && !!token} onOpenChange={(next) => !next && close()}>
        <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-xl">
          <DialogHeader>
            <DialogTitle>{t('hardware.token.title', { name: node.name })}</DialogTitle>
            <DialogDescription>{t('hardware.token.once')}</DialogDescription>
          </DialogHeader>
          {token && (
            <div className="flex flex-col gap-4">
              <CopyBlock label={t('hardware.token.serial')} text={token.serial_commands.join('\n')} />
              <CopyBlock label={t('hardware.token.secrets')} text={token.secrets_block} />
              <Button onClick={close}>{t('hardware.token.done')}</Button>
            </div>
          )}
        </DialogContent>
      </Dialog>
    </>
  )
}

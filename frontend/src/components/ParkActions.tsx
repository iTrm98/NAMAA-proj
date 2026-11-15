import { Droplets, MoreHorizontal, Power, UserPlus } from 'lucide-react'
import { DropdownMenu } from 'radix-ui'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router'
import { CreateTaskDialog, type TaskSeed } from '@/components/CreateTaskDialog'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, toast } from '@/components/ds'
import { WorkerPicker } from '@/components/WorkerPicker'
import { useCreateTask, useSwitchPump, useUpdatePark } from '@/hooks/useOps'
import { parkName } from '@/hooks/useParks'
import { ApiError } from '@/lib/api'
import type { ParkAction, ParkDetail } from '@/types'

const BTN = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-full px-5 text-sm font-medium transition-colors disabled:opacity-50'
const PRIMARY = `${BTN} bg-[var(--accent-lime)] text-[var(--on-accent)] hover:brightness-95`
const GHOST = `${BTN} border border-[var(--glass-border-strong)] text-[var(--text)] hover:bg-white/10`
const MENU_ITEM = 'flex min-h-10 cursor-pointer items-center gap-2 rounded-xl px-3 text-sm outline-none data-[highlighted]:bg-white/10'

/**
 * The park's ONE primary action (backend `actions.primary`) and the others in a "more" menu (`actions.menu`).
 * Nothing is drawn when the backend offers no action.
 */
export function ParkActions({ park }: { park: ParkDetail }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const switchPump = useSwitchPump(park.id)
  const updatePark = useUpdatePark(park.id)
  const [workerFor, setWorkerFor] = useState<ParkAction | null>(null)
  const [seed, setSeed] = useState<TaskSeed | null>(null)
  const actions = park.actions
  if (!actions || (!actions.primary && actions.menu.length === 0)) return null

  const fail = (e: unknown) => toast.error(e instanceof ApiError && e.body?.message ? e.body.message : t('common.error'))

  function run(action: ParkAction) {
    switch (action.key) {
      case 'water_now':
        return switchPump.mutate(true, { onSuccess: () => toast.success(t('parkActions.wateringStarted')), onError: fail })
      case 'stop_pump':
        return switchPump.mutate(false, { onSuccess: () => toast.success(t('parkActions.pumpStopped')), onError: fail })
      case 'toggle_auto':
        return updatePark.mutate({ auto_irrigation: !park.auto_irrigation }, { onSuccess: () => toast.success(park.auto_irrigation ? t('pump.autoOff') : t('pump.autoOn')), onError: fail })
      case 'send_worker':
        return setWorkerFor(action)
      case 'create_task':
        return setSeed({ park })
      case 'open_hardware':
        return navigate('/admin/hardware')
      case 'view_task':
        return navigate(action.task_id ? `/admin/tasks?task=${action.task_id}` : '/admin/tasks')
    }
  }

  const label = (a: ParkAction) => (a.key === 'toggle_auto' ? t(park.auto_irrigation ? 'parkActions.autoOff' : 'parkActions.autoOn') : t(`parkActions.${a.key}`))
  const busy = switchPump.isPending || updatePark.isPending
  const primary = actions.primary
  const icon = primary?.key === 'water_now' ? <Droplets className="size-4" aria-hidden /> : primary?.key === 'stop_pump' ? <Power className="size-4" aria-hidden /> : primary?.key === 'send_worker' ? <UserPlus className="size-4" aria-hidden /> : null

  return (
    <div className="flex items-center gap-2">
      {primary &&
        (primary.key === 'view_task' ? (
          <Link to={primary.task_id ? `/admin/tasks?task=${primary.task_id}` : '/admin/tasks'} className={`${PRIMARY} no-underline`}>
            {label(primary)}
          </Link>
        ) : (
          <button type="button" className={PRIMARY} disabled={busy} onClick={() => run(primary)}>
            {icon}
            {label(primary)}
          </button>
        ))}
      {actions.menu.length > 0 && (
        <DropdownMenu.Root>
          <DropdownMenu.Trigger aria-label={t('parkActions.more')} className="flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-full border border-[var(--glass-border)] bg-white/[.06] hover:bg-white/10">
            <MoreHorizontal className="size-5" aria-hidden />
          </DropdownMenu.Trigger>
          <DropdownMenu.Portal>
            <DropdownMenu.Content align="end" sideOffset={8} className="z-50 min-w-52 rounded-[var(--r-mini)] border border-[var(--glass-border-strong)] bg-popover p-2 shadow-xl">
              {actions.menu.map((a) => (
                <DropdownMenu.Item key={`${a.key}-${a.alert_id ?? ''}`} className={MENU_ITEM} disabled={busy} onSelect={() => run(a)}>
                  {label(a)}
                </DropdownMenu.Item>
              ))}
            </DropdownMenu.Content>
          </DropdownMenu.Portal>
        </DropdownMenu.Root>
      )}

      <Dialog open={workerFor !== null} onOpenChange={(open) => !open && setWorkerFor(null)}>
        <DialogContent className="sm:max-w-md">{workerFor && <SendWorkerForm key={workerFor.alert_id ?? 0} park={park} action={workerFor} onDone={() => setWorkerFor(null)} />}</DialogContent>
      </Dialog>
      <CreateTaskDialog seed={seed} onOpenChange={(open) => !open && setSeed(null)} />
    </div>
  )
}

/** "Send a worker": pick a worker, the task is created from the alert with the system title. */
function SendWorkerForm({ park, action, onDone }: { park: ParkDetail; action: ParkAction; onDone: () => void }) {
  const { t, i18n } = useTranslation()
  const create = useCreateTask()
  const [workerId, setWorkerId] = useState<number | null>(null)
  const alert = park.open_alerts.find((a) => a.id === action.alert_id) ?? park.open_alerts[0]
  const name = parkName(park, i18n.language)

  function send() {
    if (workerId === null) return
    const titleKey = `taskTitle.${alert?.type ?? 'manual'}`
    create.mutate(
      {
        ...(alert ? { alert_id: alert.id } : { park_id: park.id }),
        assigned_to: workerId,
        title: t(titleKey, { park: name }),
        title_key: titleKey,
        note: null,
        priority: alert?.priority ?? 'normal',
      },
      {
        onSuccess: (task) => {
          toast.success(task.assigned_to ? t('alerts.assignedTo', { name: task.assigned_to.name }) : t('alerts.taskUnassigned'))
          onDone()
        },
        onError: (e) => toast.error(e instanceof ApiError && e.body?.message ? e.body.message : t('common.error')),
      },
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <DialogHeader>
        <DialogTitle>{t('parkActions.send_worker')}</DialogTitle>
        <DialogDescription>{alert ? `${name} · ${t(`alertType.${alert.type}`)}` : name}</DialogDescription>
      </DialogHeader>
      <WorkerPicker value={workerId} onChange={setWorkerId} />
      <DialogFooter>
        <button type="button" className={GHOST} onClick={onDone}>
          {t('common.cancel')}
        </button>
        <button type="button" className={PRIMARY} disabled={workerId === null || create.isPending} onClick={send}>
          {create.isPending ? t('createTask.submitting') : t('parkActions.send')}
        </button>
      </DialogFooter>
    </div>
  )
}

import { type FormEvent, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { parkName } from '@/hooks/useParks'
import { useCreateTask, useWorkers } from '@/hooks/useOps'
import { ApiError } from '@/lib/api'
import type { AlertType, TaskPriority } from '@/types'

const PRIORITIES: TaskPriority[] = ['urgent', 'high', 'normal', 'low']
const NONE = 'none'

export interface TaskSeed {
  park: { id: number; name_ar: string; name_en: string }
  /** The alert's current priority: the task starts with it. */
  alert?: { id: number; type: AlertType; priority: TaskPriority }
}

/**
 * Create a task from an alert (priority prefilled with the alert's priority) or for
 * a park. Workers are listed with their open task count so the admin can
 * spread the load. On success: "Task assigned to Ahmed".
 */
export function CreateTaskDialog({ seed, onOpenChange }: { seed: TaskSeed | null; onOpenChange: (open: boolean) => void }) {
  return (
    <Dialog open={seed !== null} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">{seed && <CreateTaskForm key={`${seed.park.id}-${seed.alert?.id}`} seed={seed} onDone={() => onOpenChange(false)} />}</DialogContent>
    </Dialog>
  )
}

function CreateTaskForm({ seed, onDone }: { seed: TaskSeed; onDone: () => void }) {
  const { t, i18n } = useTranslation()
  const workers = useWorkers()
  const create = useCreateTask()
  const name = parkName(seed.park, i18n.language)

  const [workerId, setWorkerId] = useState<string>(NONE)
  const [priority, setPriority] = useState<TaskPriority>(seed.alert?.priority ?? 'normal')
  const [title, setTitle] = useState(() => t(`taskTitle.${seed.alert?.type ?? 'manual'}`, { park: name }))
  const [note, setNote] = useState('')

  function submit(event: FormEvent) {
    event.preventDefault()
    create.mutate(
      {
        ...(seed.alert ? { alert_id: seed.alert.id } : { park_id: seed.park.id }),
        assigned_to: workerId === NONE ? null : Number(workerId),
        title: title.trim(),
        note: note.trim() || null,
        priority,
      },
      {
        onSuccess: (task) => {
          toast.success(task.assigned_to ? t('alerts.assignedTo', { name: task.assigned_to.name }) : t('alerts.taskUnassigned'))
          onDone()
        },
      },
    )
  }

  const error = create.error instanceof ApiError ? (create.error.body?.message ?? t('common.error')) : create.error ? t('common.error') : null

  return (
    <form onSubmit={submit} className="flex flex-col gap-4">
      <DialogHeader>
        <DialogTitle>{t('createTask.title')}</DialogTitle>
        <DialogDescription>
          {seed.alert ? `${name} · ${t('createTask.forAlert', { type: t(`alertType.${seed.alert.type}`) })}` : t('createTask.forPark', { park: name })}
        </DialogDescription>
      </DialogHeader>

      {error && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      <div className="flex flex-col gap-2">
        <Label htmlFor="task-worker">{t('createTask.worker')}</Label>
        <Select value={workerId} onValueChange={setWorkerId}>
          <SelectTrigger id="task-worker" className="w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={NONE}>{t('createTask.unassigned')}</SelectItem>
            {workers.data?.map((w) => (
              <SelectItem key={w.id} value={String(w.id)}>
                {t('createTask.workerLoad', { name: w.name, n: w.open_tasks + w.in_progress_tasks })}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="task-priority">
          {t('createTask.priority')}
          {seed.alert && <span className="ms-1 text-xs font-normal text-muted-foreground">({t('createTask.suggested')}: {t(`priority.${seed.alert.priority}`)})</span>}
        </Label>
        <Select value={priority} onValueChange={(v) => setPriority(v as TaskPriority)}>
          <SelectTrigger id="task-priority" className="w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {PRIORITIES.map((p) => (
              <SelectItem key={p} value={p}>
                {t(`priority.${p}`)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="task-title">{t('createTask.taskTitle')}</Label>
        <Input id="task-title" value={title} maxLength={150} required onChange={(e) => setTitle(e.target.value)} />
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="task-note">{t('createTask.note')}</Label>
        <Textarea id="task-note" value={note} maxLength={1000} rows={3} placeholder={t('createTask.notePlaceholder')} onChange={(e) => setNote(e.target.value)} />
      </div>

      <DialogFooter>
        <Button type="button" variant="ghost" onClick={onDone}>
          {t('common.cancel')}
        </Button>
        <Button type="submit" disabled={create.isPending || !title.trim()}>
          {create.isPending ? t('createTask.submitting') : t('createTask.submit')}
        </Button>
      </DialogFooter>
    </form>
  )
}

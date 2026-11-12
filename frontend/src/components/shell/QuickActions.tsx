import { Camera, Layers, Plus, Search } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation, useNavigate } from 'react-router'
import { MAP_LAYERS_EVENT } from '@/components/OverviewMap'
import { CreateTaskDialog, type TaskSeed } from '@/components/CreateTaskDialog'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, EmptyState, Rail, Skeleton } from '@/components/ds'
import { Input } from '@/components/ui/input'
import { parkName, useParks } from '@/hooks/useParks'
import { cn } from '@/lib/utils'
import type { Park } from '@/types'

type Mode = 'search' | 'task' | null

/** Event the Overview header button sends to open the park picker for a new task. */
export const CREATE_TASK_EVENT = 'namaa:create-task'

/** Admin quick-action rail: search parks, create a task, map layers, cameras. */
export function QuickActions({ className }: { className?: string }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { pathname } = useLocation()
  const onOverview = pathname === '/admin' || pathname === '/admin/'
  const [mode, setMode] = useState<Mode>(null)
  const [seed, setSeed] = useState<TaskSeed | null>(null)

  useEffect(() => {
    const open = () => setMode('task')
    window.addEventListener(CREATE_TASK_EVENT, open)
    return () => window.removeEventListener(CREATE_TASK_EVENT, open)
  }, [])

  return (
    <>
      <Rail
        ariaLabel={t('rail.label')}
        className={className}
        items={[
          { icon: <Search />, label: t('rail.search'), onClick: () => setMode('search') },
          { icon: <Plus />, label: t('rail.createTask'), onClick: () => setMode('task') },
          { icon: <Layers />, label: t('rail.layers'), onClick: () => (onOverview ? window.dispatchEvent(new Event(MAP_LAYERS_EVENT)) : navigate('/admin')) },
          { icon: <Camera />, label: t('rail.cameras'), onClick: () => navigate('/admin/hardware') },
        ]}
      />
      <ParkPicker
        mode={mode}
        onClose={() => setMode(null)}
        onPick={(park) => {
          if (mode === 'task') setSeed({ park })
          else navigate(`/admin/parks/${park.id}`)
          setMode(null)
        }}
      />
      <CreateTaskDialog seed={seed} onOpenChange={(open) => !open && setSeed(null)} />
    </>
  )
}

function ParkPicker({ mode, onClose, onPick }: { mode: Mode; onClose: () => void; onPick: (park: Park) => void }) {
  const { t, i18n } = useTranslation()
  const parks = useParks()
  const [q, setQ] = useState('')
  const shown = useMemo(() => {
    const needle = q.trim().toLowerCase()
    return (parks.data ?? []).filter((p) => !needle || `${p.name_ar} ${p.name_en} ${p.district ?? ''}`.toLowerCase().includes(needle))
  }, [parks.data, q])

  return (
    <Dialog open={mode !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{mode === 'task' ? t('quick.pickParkForTask') : t('quick.searchTitle')}</DialogTitle>
          <DialogDescription className="sr-only">{t('quick.searchTitle')}</DialogDescription>
        </DialogHeader>
        <Input autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('quick.searchPlaceholder')} aria-label={t('quick.searchPlaceholder')} className="h-10 rounded-[var(--r-input)]" />
        <div className="flex max-h-72 flex-col gap-1 overflow-y-auto">
          {parks.isPending && [0, 1, 2].map((i) => <Skeleton key={i} h={44} />)}
          {parks.isError && <EmptyState title={t('common.error')} />}
          {parks.data && shown.length === 0 && <EmptyState title={t('quick.noMatch')} />}
          {shown.map((p) => (
            <button
              key={p.id}
              type="button"
              onClick={() => onPick(p)}
              className={cn('flex min-h-11 items-center justify-between gap-2 rounded-xl px-3 text-start text-sm hover:bg-white/10')}
            >
              <span className="truncate">{parkName(p, i18n.language)}</span>
              <span className="text-xs text-text-2">{p.district}</span>
            </button>
          ))}
        </div>
      </DialogContent>
    </Dialog>
  )
}

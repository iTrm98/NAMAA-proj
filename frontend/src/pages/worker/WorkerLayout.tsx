import { Outlet } from 'react-router'
import { AppHeader, SoundButton } from '@/components/AppHeader'

/** /worker: phone first (390 px), one column, the "Enable sound" button until sound is unlocked. */
export default function WorkerLayout() {
  return (
    <div className="flex min-h-svh flex-col">
      <AppHeader actions={<SoundButton />} />
      <main className="mx-auto w-full max-w-xl flex-1 px-4 py-4">
        <Outlet />
      </main>
    </div>
  )
}

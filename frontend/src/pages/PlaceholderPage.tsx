import { Construction } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { AppHeader } from '@/components/AppHeader'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'

/** /worker and /municipality until their screens arrive in Sprint 2. */
export function PlaceholderPage({ titleKey, bodyKey }: { titleKey: string; bodyKey: string }) {
  const { t } = useTranslation()

  return (
    <div className="flex min-h-svh flex-col">
      <AppHeader />
      <main className="flex flex-1 items-start justify-center px-4 py-10">
        <Card className="w-full max-w-md">
          <CardHeader>
            <div className="mb-2 flex size-10 items-center justify-center rounded-xl bg-muted text-muted-foreground">
              <Construction className="size-5" />
            </div>
            <CardTitle>{t(titleKey)}</CardTitle>
            <CardDescription>{t(bodyKey)}</CardDescription>
          </CardHeader>
          <CardContent />
        </Card>
      </main>
    </div>
  )
}

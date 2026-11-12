import type { TFunction } from 'i18next'
import type { Task } from '@/types'

/** A person's name in the UI language (Arabic name when the language is Arabic and one exists). */
export function personName(person: { name: string; name_ar?: string | null }, language: string): string {
  return language === 'ar' && person.name_ar ? person.name_ar : person.name
}

/**
 * A task's title in the UI language: a system title is translated from its key with the park name,
 * a free-text title is shown as typed.
 */
export function taskTitle(
  t: TFunction,
  language: string,
  task: Pick<Task, 'title' | 'title_key'> & { title_park?: { name_ar: string; name_en: string } | null; park?: { name_ar: string; name_en: string } | null },
): string {
  if (!task.title_key) return task.title
  const park = task.title_park ?? task.park
  const name = park ? (language === 'ar' ? park.name_ar : park.name_en) : ''
  return t(task.title_key, { park: name })
}

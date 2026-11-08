import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import ar from './ar.json'
import en from './en.json'

/**
 * Arabic is the default. The choice is remembered in localStorage; the document
 * gets lang + dir (rtl for Arabic, ltr for English) on every change.
 */
export type Language = 'ar' | 'en'

const KEY = 'namaa.lang'

function stored(): Language | null {
  try {
    const value = localStorage.getItem(KEY)
    return value === 'ar' || value === 'en' ? value : null
  } catch {
    return null
  }
}

function applyToDocument(lng: string) {
  document.documentElement.lang = lng
  document.documentElement.dir = lng === 'ar' ? 'rtl' : 'ltr'
}

void i18n.use(initReactI18next).init({
  resources: { ar: { translation: ar }, en: { translation: en } },
  lng: stored() ?? 'ar',
  fallbackLng: 'ar',
  interpolation: { escapeValue: false },
})

applyToDocument(i18n.language)
i18n.on('languageChanged', applyToDocument)

export function setLanguage(lng: Language) {
  try {
    localStorage.setItem(KEY, lng)
  } catch {
    // ignore
  }
  void i18n.changeLanguage(lng)
}

/** First sign-in on this browser: use the account's locale unless the user already picked one. */
export function adoptAccountLocale(lng: Language) {
  if (stored() === null) void i18n.changeLanguage(lng)
}

export default i18n

import { useTranslation } from 'react-i18next'

/** 1 in LTR, -1 in RTL. Multiply every directional motion offset (`x`) by it. */
export function useDir(): 1 | -1 {
  const { i18n } = useTranslation()
  return i18n.dir() === 'rtl' ? -1 : 1
}

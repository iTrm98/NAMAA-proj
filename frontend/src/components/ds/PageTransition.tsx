import { motion } from 'motion/react'
import type { ReactNode } from 'react'
import { useLocation } from 'react-router'

/** Page transition: fade + 8 px rise on every route change (instant under reduced motion). */
export function PageTransition({ children, className }: { children: ReactNode; className?: string }) {
  const { pathname } = useLocation()
  return (
    <motion.div
      key={pathname}
      className={className}
      initial={{ opacity: 0, y: 8 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.2, ease: 'easeOut' }}
    >
      {children}
    </motion.div>
  )
}

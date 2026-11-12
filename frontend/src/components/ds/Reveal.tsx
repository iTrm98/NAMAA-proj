import { motion } from 'motion/react'
import type { ReactNode } from 'react'

/** Staggered entry (fade + 8 px rise, ~40 ms apart, capped) when the item first mounts. Instant under reduced motion. */
export function Reveal({ index = 0, children, className }: { index?: number; children: ReactNode; className?: string }) {
  return (
    <motion.div
      className={className}
      initial={{ opacity: 0, y: 8 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.25, ease: 'easeOut', delay: Math.min(index, 8) * 0.04 }}
    >
      {children}
    </motion.div>
  )
}

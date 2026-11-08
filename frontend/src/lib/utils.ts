import { type ClassValue, clsx } from "clsx"
import { twMerge } from "tailwind-merge"

/** Merge Tailwind class names (shadcn/ui helper). */
export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

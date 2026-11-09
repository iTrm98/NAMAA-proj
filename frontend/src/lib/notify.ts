import { useSyncExternalStore } from 'react'

/**
 * New-task alert on the worker's phone: a short beep made with the Web Audio
 * API (no sound file) and a vibration. Browsers block sound until the user
 * taps something, so the header shows a one-time "Enable sound" button that
 * calls unlockSound() from that tap.
 */
let context: AudioContext | null = null
const listeners = new Set<() => void>()

function emit() {
  for (const listener of listeners) listener()
}

export function soundEnabled(): boolean {
  return context?.state === 'running'
}

export async function unlockSound(): Promise<void> {
  try {
    const Ctor = window.AudioContext ?? (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext
    context ??= new Ctor()
    await context.resume()
    beep(0.05) // a very short, quiet confirmation
  } catch {
    // no audio on this device
  }
  emit()
}

/** Two short tones (about 0.4 s). */
export function beep(volume = 0.25) {
  if (!context || context.state !== 'running') return
  const now = context.currentTime
  for (const [offset, frequency] of [
    [0, 880],
    [0.2, 1175],
  ] as const) {
    const oscillator = context.createOscillator()
    const gain = context.createGain()
    oscillator.type = 'sine'
    oscillator.frequency.value = frequency
    gain.gain.setValueAtTime(0.0001, now + offset)
    gain.gain.exponentialRampToValueAtTime(volume, now + offset + 0.02)
    gain.gain.exponentialRampToValueAtTime(0.0001, now + offset + 0.18)
    oscillator.connect(gain).connect(context.destination)
    oscillator.start(now + offset)
    oscillator.stop(now + offset + 0.2)
  }
}

export function vibrate() {
  try {
    navigator.vibrate?.([200, 100, 200])
  } catch {
    // not supported
  }
}

export function useSoundEnabled(): boolean {
  return useSyncExternalStore(
    (listener) => {
      listeners.add(listener)
      return () => listeners.delete(listener)
    },
    soundEnabled,
  )
}

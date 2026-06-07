/**
 * useToast — toast notification composable.
 *
 * Provides a reactive toast queue and convenience methods for each severity level.
 *
 * Usage:
 *   const toast = useToast()
 *   toast.success('Agent saved.')
 *   toast.error('Session expired.', { action: 'login' })
 *   toast.warning('Rate limited. Retrying in 30s.', { retryAfter: 30 })
 *
 * In a component, call useToast() at the top level (not inside onMounted).
 * The ToastContainer should be rendered once in App.vue.
 */
import { reactive, readonly } from 'vue'

export interface ToastOptions {
  action?: string
  onAction?: () => void
  retryAfter?: number
}

export interface ToastItem {
  id: string
  severity: 'error' | 'warning' | 'success' | 'info'
  message: string
  action?: string
  onAction?: () => void
}

let toastIdCounter = 0

function generateId(): string {
  return `toast-${++toastIdCounter}-${Date.now()}`
}

class ToastManager {
  private readonly toasts = reactive<ToastItem[]>([])

  get list(): readonly ToastItem[] {
    return readonly(this.toasts) as readonly ToastItem[]
  }

  private add(severity: ToastItem['severity'], message: string, options?: ToastOptions): string {
    const id = generateId()
    this.toasts.push({
      id,
      severity,
      message,
      action: options?.action,
      onAction: options?.onAction,
    })
    return id
  }

  remove(id: string): void {
    const idx = this.toasts.findIndex(t => t.id === id)
    if (idx !== -1) {
      this.toasts.splice(idx, 1)
    }
  }

  success(message: string, options?: ToastOptions): string {
    return this.add('success', message, options)
  }

  warning(message: string, options?: ToastOptions): string {
    return this.add('warning', message, options)
  }

  error(message: string, options?: ToastOptions): string {
    return this.add('error', message, options)
  }

  info(message: string, options?: ToastOptions): string {
    return this.add('info', message, options)
  }
}

// Module-level singleton so the toast state persists across component re-renders
const manager = new ToastManager()

export function useToast() {
  return {
    toasts: manager.list,
    success: (message: string, options?: ToastOptions) => manager.success(message, options),
    warning: (message: string, options?: ToastOptions) => manager.warning(message, options),
    error: (message: string, options?: ToastOptions) => manager.error(message, options),
    info: (message: string, options?: ToastOptions) => manager.info(message, options),
    dismiss: (id: string) => manager.remove(id),
  }
}
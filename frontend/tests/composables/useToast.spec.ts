import { describe, it, expect, beforeEach } from 'vitest'
import { useToast } from '@/composables/useToast'

describe('useToast', () => {
  function clearToasts(): void {
    const { toasts, dismiss } = useToast()
    // manager.list returns readonly(reactive[]) — not a Ref, so .length not .value.length
    while (toasts.length > 0) {
      dismiss(toasts[0].id)
    }
  }

  beforeEach(() => {
    clearToasts()
  })

  it('returns a toast list, dismiss, and severity helpers', () => {
    const toast = useToast()
    expect(toast.toasts).toBeDefined()
    expect(typeof toast.dismiss).toBe('function')
    expect(typeof toast.success).toBe('function')
    expect(typeof toast.warning).toBe('function')
    expect(typeof toast.error).toBe('function')
    expect(typeof toast.info).toBe('function')
  })

  it('success() adds a toast with severity=success', () => {
    const { success, toasts } = useToast()
    const id = success('Agent saved.')

    expect(toasts.length).toBe(1)
    expect(toasts[0].id).toBe(id)
    expect(toasts[0].severity).toBe('success')
    expect(toasts[0].message).toBe('Agent saved.')
  })

  it('warning() adds a toast with severity=warning', () => {
    const { warning, toasts } = useToast()
    warning('Validation failed.')

    expect(toasts[0].severity).toBe('warning')
    expect(toasts[0].message).toBe('Validation failed.')
  })

  it('error() adds a toast with severity=error', () => {
    const { error, toasts } = useToast()
    error('Session expired.')

    expect(toasts[0].severity).toBe('error')
    expect(toasts[0].message).toBe('Session expired.')
  })

  it('info() adds a toast with severity=info', () => {
    const { info, toasts } = useToast()
    info('Rate limit hit.')

    expect(toasts[0].severity).toBe('info')
    expect(toasts[0].message).toBe('Rate limit hit.')
  })

  it('success() with action option sets action and onAction', () => {
    const { success, toasts } = useToast()
    const onAction = () => {}
    success('Retry failed.', { action: 'Retry', onAction })

    expect(toasts[0].action).toBe('Retry')
    expect(toasts[0].onAction).toBe(onAction)
  })

  it('dismiss() removes a toast by id', () => {
    const { success, dismiss, toasts } = useToast()
    const id = success('Test toast')
    expect(toasts.length).toBe(1)

    dismiss(id)
    expect(toasts.length).toBe(0)
  })

  it('dismiss() with unknown id is a no-op', () => {
    const { dismiss } = useToast()
    expect(() => dismiss('does-not-exist')).not.toThrow()
  })

  it('each call gets a unique id', () => {
    const { success } = useToast()
    const id1 = success('First')
    const id2 = success('Second')
    expect(id1).not.toBe(id2)
  })
})
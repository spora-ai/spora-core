/**
 * useConfirmDialog — smoke test that ensures the composable can be invoked
 * without throwing and returns a `confirm` function.
 *
 * Because the composable mounts a Vue app behind the scenes, we mock the
 * ConfirmDialog component to a no-op so we don't pull a whole component tree.
 */
import { describe, it, expect, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('@/components/ui/ConfirmDialog.vue', () => ({
  default: defineComponent({
    name: 'ConfirmDialogStub',
    methods: {
      open(_message: string, _title?: string, _confirmLabel?: string) {
        return Promise.resolve(true)
      },
    },
    render() {
      return h('div')
    },
  }),
}))

describe('useConfirmDialog', () => {
  it('returns an object with a confirm() function', async () => {
    const { useConfirmDialog } = await import('@/composables/useConfirmDialog')
    const result = useConfirmDialog()
    expect(typeof result.confirm).toBe('function')
  })

  it('confirm() resolves to a boolean (or false when dialogRef is not ready)', async () => {
    const { useConfirmDialog } = await import('@/composables/useConfirmDialog')
    const { confirm } = useConfirmDialog()

    // The dialog uses the stub above which always resolves true. Even if the
    // singleton ref isn't populated yet (because this is the very first call),
    // confirm() must resolve to a boolean.
    const result = await confirm('Are you sure?')
    expect(typeof result).toBe('boolean')
  })
})

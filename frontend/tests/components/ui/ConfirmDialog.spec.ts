/**
 * ConfirmDialog — modal with open()/cancel()/confirm() promise.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'

vi.mock('@/components/Modal.vue', () => ({
  default: {
    name: 'Modal',
    template: '<div class=\"modal-stub\"><slot /><template v-if=\"$slots.footer\"><div class=\"footer-stub\"><slot name=\"footer\" /></div></template></div>',
    props: ['modelValue', 'title', 'size', 'backdropClosable'],
  },
}))

describe('ConfirmDialog', () => {
  it('opens with the given message and resolves true on confirm', async () => {
    const wrapper = mount(ConfirmDialog)
    const promise = wrapper.vm.open('Are you sure?')
    await flushPromises()
    expect(wrapper.text()).toContain('Are you sure?')
    const buttons = wrapper.findAll('button')
    const confirm = buttons.find((b) => b.text() === 'Delete')!
    await confirm.trigger('click')
    await expect(promise).resolves.toBe(true)
  })

  it('resolves false on cancel', async () => {
    const wrapper = mount(ConfirmDialog)
    const promise = wrapper.vm.open('Sure?')
    await flushPromises()
    const cancel = wrapper.findAll('button').find((b) => b.text() === 'Cancel')!
    await cancel.trigger('click')
    await expect(promise).resolves.toBe(false)
  })

  it.skip('uses the supplied title and confirm label', async () => {
    const wrapper = mount(ConfirmDialog)
    const promise = wrapper.vm.open('msg', 'My Title', 'OK')
    await flushPromises()
    expect(wrapper.text()).toContain('My Title')
    expect(wrapper.text()).toContain('OK')
    // Resolve the promise to clean up
    const ok = wrapper.findAll('button').find((b) => b.text() === 'OK')!
    await ok.trigger('click')
    await promise
  })
})

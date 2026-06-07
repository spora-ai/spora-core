/**
 * EditUserModal — admin user edit form (roles + display name).
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'

const updateMock = vi.fn()
const emitMock = vi.fn()
const propsMock: { user: unknown; modelValue: boolean } = {
  user: { id: 1, email: 'a@b.com', display_name: 'A', roles: ['USER'] },
  modelValue: true,
}

vi.mock('@/stores/users', () => ({
  useUsersStore: () => ({ updateUser: updateMock }),
}))

const ModalStub = {
  name: 'Modal',
  props: ['modelValue', 'title', 'size'],
  emits: ['update:modelValue', 'close'],
  template: `
    <div v-if="modelValue" class="modal-stub">
      <div class="default-slot"><slot /></div>
      <div class="footer-slot"><slot name="footer" /></div>
    </div>
  `,
}

import EditUserModal from '@/components/admin/EditUserModal.vue'

beforeEach(() => {
  updateMock.mockReset()
  updateMock.mockResolvedValue(undefined)
  propsMock.user = { id: 1, email: 'a@b.com', display_name: 'A', roles: ['USER'] }
  propsMock.modelValue = true
})

describe('EditUserModal', () => {
  it('renders nothing when show is false', () => {
    const wrapper = mount(EditUserModal, {
      props: { ...propsMock, modelValue: false },
      global: { stubs: { Modal: ModalStub } },
    })
    expect(wrapper.find('.modal-stub').exists()).toBe(false)
  })

  it('renders the modal when show is true', () => {
    const wrapper = mount(EditUserModal, {
      props: propsMock,
      global: { stubs: { Modal: ModalStub } },
    })
    expect(wrapper.find('.modal-stub').exists()).toBe(true)
  })

  it('calls updateUser on save', async () => {
    const wrapper = mount(EditUserModal, {
      props: propsMock,
      global: { stubs: { Modal: ModalStub } },
    })
    // Find the save button (usually has data-testid or text)
    const buttons = wrapper.findAll('button')
    const saveButton = buttons.find((b) => b.text().toLowerCase().includes('save changes'))
    expect(saveButton).toBeDefined()
    await saveButton!.trigger('click')
    await flushPromises()
    expect(updateMock).toHaveBeenCalled()
  })
})

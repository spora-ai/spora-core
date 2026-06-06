/**
 * UsersPage — admin user list with role + delete actions.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { ref } from 'vue'

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: {} }),
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { name: 'RouterLink', template: '<a><slot /></a>' },
}))

vi.mock('@/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn() },
  ApiError: class ApiError extends Error {
    constructor(message: string) { super(message); this.name = 'ApiError' }
  },
}))

const usersRef = ref<Array<{ id: number; email: string; display_name: string; roles: string[]; is_active: boolean }>>([])
const fetchUsersMock = vi.fn()
const updateUserMock = vi.fn()
const deleteUserMock = vi.fn()
vi.mock('@/stores/users', () => ({
  useUsersStore: () => ({
    get users() { return usersRef.value },
    loading: false,
    error: null,
    fetchUsers: fetchUsersMock,
    updateUser: updateUserMock,
    deleteUser: deleteUserMock,
  }),
}))

import { api } from '@/api/client'

const EditUserModalStub = { name: 'EditUserModal', template: '<div v-if="show" class="edit-modal-stub" />' }
const DeleteUserModalStub = { name: 'DeleteUserModal', template: '<div v-if="show" class="delete-modal-stub" />' }

import UsersPage from '@/pages/admin/UsersPage.vue'

const getMock = api.get as ReturnType<typeof vi.fn>

beforeEach(() => {
  setActivePinia(createPinia())
  usersRef.value = []
  fetchUsersMock.mockReset()
  fetchUsersMock.mockResolvedValue(undefined)
  updateUserMock.mockReset()
  deleteUserMock.mockReset()
  getMock.mockReset()
})

describe('UsersPage', () => {
  it('mounts and fetches users on mount', async () => {
    const wrapper = mount(UsersPage, {
      global: { stubs: { EditUserModal: EditUserModalStub, DeleteUserModal: DeleteUserModalStub, RouterLink: true } },
    })
    await flushPromises()
    expect(fetchUsersMock).toHaveBeenCalled()
  })

  it('renders the user list', async () => {
    usersRef.value = [
      { id: 1, email: 'alice@example.com', display_name: 'Alice', roles: ['ADMIN'], is_active: true },
      { id: 2, email: 'bob@example.com', display_name: 'Bob', roles: ['USER'], is_active: true },
    ]
    const wrapper = mount(UsersPage, {
      global: { stubs: { EditUserModal: EditUserModalStub, DeleteUserModal: DeleteUserModalStub, RouterLink: true } },
    })
    await flushPromises()
    expect(wrapper.text()).toContain('alice@example.com')
    expect(wrapper.text()).toContain('bob@example.com')
  })

  it('shows an empty state when there are no users', async () => {
    const wrapper = mount(UsersPage, {
      global: { stubs: { EditUserModal: EditUserModalStub, DeleteUserModal: DeleteUserModalStub, RouterLink: true } },
    })
    await flushPromises()
    expect(wrapper.text()).toMatch(/no users|empty/i)
  })
})

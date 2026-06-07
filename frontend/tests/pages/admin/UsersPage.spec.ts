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

const usersRef = ref<Array<{ id: number; email: string; display_name: string; roles: string[]; is_active: boolean; verified: boolean }>>([])
const fetchUsersMock = vi.fn()
const updateUserMock = vi.fn()
const deleteUserMock = vi.fn()
const setVerifiedMock = vi.fn()
const grantRoleMock = vi.fn()
const revokeRoleMock = vi.fn()
vi.mock('@/stores/users', () => ({
  useUsersStore: () => ({
    get users() { return usersRef.value },
    loading: false,
    error: null,
    fetchUsers: fetchUsersMock,
    updateUser: updateUserMock,
    deleteUser: deleteUserMock,
    setVerified: setVerifiedMock,
    grantRole: grantRoleMock,
    revokeRole: revokeRoleMock,
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
  setVerifiedMock.mockReset()
  grantRoleMock.mockReset()
  revokeRoleMock.mockReset()
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
      { id: 1, email: 'alice@example.com', display_name: 'Alice', roles: ['ADMIN'], is_active: true, verified: true },
      { id: 2, email: 'bob@example.com', display_name: 'Bob', roles: ['USER'], is_active: true, verified: false },
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

  it('toggleVerified: marks an unverified user as verified and toasts the inverted-ternary success branch', async () => {
    // Covers the SonarQube S7735 inversion: `!user.verified ? '...' : '...'`
    // became `user.verified ? '...' : '...'`. The first branch (unverified →
    // "User marked as verified.") is exercised when user.verified is false.
    setVerifiedMock.mockResolvedValueOnce({ id: 2, verified: true })
    usersRef.value = [
      { id: 2, email: 'bob@example.com', display_name: 'Bob', roles: ['USER'], is_active: true, verified: false },
    ]
    const wrapper = mount(UsersPage, {
      global: { stubs: { EditUserModal: EditUserModalStub, DeleteUserModal: DeleteUserModalStub, RouterLink: true } },
    })
    await flushPromises()
    // The verify toggle button has title="Mark as verified" when user is unverified.
    const toggleBtn = wrapper.findAll('button').find((b) => b.attributes('title') === 'Mark as verified')
    expect(toggleBtn).toBeDefined()
    await toggleBtn!.trigger('click')
    await flushPromises()
    expect(setVerifiedMock).toHaveBeenCalledWith(2, true)
  })

  it('toggleVerified: marks a verified user as unverified and toasts the other ternary branch', async () => {
    setVerifiedMock.mockResolvedValueOnce({ id: 1, verified: false })
    usersRef.value = [
      { id: 1, email: 'alice@example.com', display_name: 'Alice', roles: ['ADMIN'], is_active: true, verified: true },
    ]
    const wrapper = mount(UsersPage, {
      global: { stubs: { EditUserModal: EditUserModalStub, DeleteUserModal: DeleteUserModalStub, RouterLink: true } },
    })
    await flushPromises()
    const toggleBtn = wrapper.findAll('button').find((b) => b.attributes('title') === 'Mark as unverified')
    expect(toggleBtn).toBeDefined()
    await toggleBtn!.trigger('click')
    await flushPromises()
    expect(setVerifiedMock).toHaveBeenCalledWith(1, false)
  })
})

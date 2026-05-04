<script setup lang="ts">
/**
 * UsersPage — admin user management page.
 * Route: /settings/admin/users
 */
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useUsersStore } from '@/stores/users'
import type { User } from '@/types/user'
import { ApiError } from '@/api/client'
import { useToast } from '@/composables/useToast'
import Icon from '@/components/ui/Icon.vue'
import Modal from '@/components/Modal.vue'

const auth = useAuthStore()
const usersStore = useUsersStore()
const toast = useToast()

// ── Pagination ─────────────────────────────────────────────────────────────

const deletingUser = ref<User | null>(null)
const deleting = ref(false)
const deleteError = ref<string | null>(null)

const isDeleteOpen = computed({
  get: () => deletingUser.value !== null,
  set: (val: boolean) => { if (!val) deletingUser.value = null },
})

function openDelete(user: User): void {
  deletingUser.value = user
  deleteError.value = null
}

function closeDelete(): void {
  deletingUser.value = null
}

async function confirmDelete(): Promise<void> {
  if (!deletingUser.value) return
  deleting.value = true
  deleteError.value = null
  try {
    await usersStore.deleteUser(deletingUser.value.id)
    toast.success('User deleted.')
    deletingUser.value = null
  } catch (e) {
    if (e instanceof ApiError && e.code === 'CANNOT_DELETE_SELF') {
      deleteError.value = 'You cannot delete your own account.'
    } else {
      deleteError.value = e instanceof ApiError ? e.message : 'Failed to delete user.'
    }
  } finally {
    deleting.value = false
  }
}

const currentPage = ref(1)
const lastPage = ref(1)

onMounted(async () => {
  await loadPage(1)
})

async function loadPage(page: number): Promise<void> {
  try {
    const result = await usersStore.fetchUsers(page)
    currentPage.value = result.current_page
    lastPage.value = result.last_page
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : 'Failed to load users.')
  }
}

// ── Create modal ────────────────────────────────────────────────────────────

const showCreate = ref(false)
const createForm = ref({ email: '', password: '' })
const creating = ref(false)
const createError = ref<string | null>(null)

async function createUser(): Promise<void> {
  if (!createForm.value.email.trim() || !createForm.value.password) return
  createError.value = null
  creating.value = true
  try {
    await usersStore.createUser({ email: createForm.value.email, password: createForm.value.password })
    toast.success('User created.')
    showCreate.value = false
    createForm.value = { email: '', password: '' }
  } catch (e) {
    createError.value = e instanceof ApiError ? e.message : 'Failed to create user.'
  } finally {
    creating.value = false
  }
}

// ── Edit modal ─────────────────────────────────────────────────────────────

const editingUser = ref<User | null>(null)
const editForm = ref({ username: '', isAdmin: false, suspended: false })
const savingEdit = ref(false)
const editError = ref<string | null>(null)

const isEditingOpen = computed({
  get: () => editingUser.value !== null,
  set: (val: boolean) => { if (!val) editingUser.value = null },
})

function openEdit(user: User): void {
  editingUser.value = user
  editForm.value = {
    username: user.username ?? '',
    isAdmin: user.roles.includes('ADMIN'),
    suspended: user.roles.includes('SUSPENDED'),
  }
  editError.value = null
}

function closeEdit(): void {
  editingUser.value = null
}

async function saveEdit(): Promise<void> {
  if (!editingUser.value) return
  savingEdit.value = true
  editError.value = null
  try {
    const wasAdmin = editingUser.value.roles.includes('ADMIN')
    const isAdmin = editForm.value.isAdmin

    // Update username and suspended status
    await usersStore.updateUser(editingUser.value.id, {
      username: editForm.value.username || undefined,
      suspended: editForm.value.suspended,
    })

    // Sync admin role
    if (wasAdmin && !isAdmin) {
      await usersStore.revokeRole(editingUser.value.id, 'ADMIN')
    } else if (!wasAdmin && isAdmin) {
      await usersStore.grantRole(editingUser.value.id, 'ADMIN')
    }
    toast.success('User updated.')
    editingUser.value = null
  } catch (e) {
    editError.value = e instanceof ApiError ? e.message : 'Failed to update user.'
  } finally {
    savingEdit.value = false
  }
}

// ── Role toggling (admin only) ──────────────────────────────────────────────

const togglingRole = ref<number | null>(null)

async function toggleAdminRole(user: User): Promise<void> {
  const isAdmin = user.roles.includes('ADMIN')
  togglingRole.value = user.id
  try {
    if (isAdmin) {
      await usersStore.revokeRole(user.id, 'ADMIN')
    } else {
      await usersStore.grantRole(user.id, 'ADMIN')
    }
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : 'Failed to update role.')
  } finally {
    togglingRole.value = null
  }
}

// ── Helpers ────────────────────────────────────────────────────────────────

function isOwnAccount(user: User): boolean {
  return auth.user?.id === user.id
}
</script>

<template>
  <div class="flex-1 min-w-0">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-xl font-semibold">Users</h1>
        <p class="text-sm text-muted-foreground mt-0.5">Manage user accounts and roles.</p>
      </div>
      <button
        @click="showCreate = true"
        class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90"
      >
        <Icon name="plus" class="h-4 w-4 mr-1.5" />
        Create User
      </button>
    </div>

    <!-- Loading -->
    <div v-if="usersStore.loading && usersStore.users.length === 0" class="flex items-center justify-center py-12 text-sm text-muted-foreground">
      Loading…
    </div>

    <!-- Error -->
    <div v-else-if="usersStore.error && usersStore.users.length === 0" class="rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive">
      {{ usersStore.error }}
    </div>

    <!-- Table -->
    <div v-else class="rounded-xl border border-border overflow-x-scroll">
      <table class="w-full text-sm">
        <thead class="bg-muted/40">
          <tr>
            <th class="text-left px-4 py-3 font-medium text-muted-foreground">ID</th>
            <th class="text-left px-4 py-3 font-medium text-muted-foreground">Email</th>
            <th class="text-left px-4 py-3 font-medium text-muted-foreground">Username</th>
            <th class="text-left px-4 py-3 font-medium text-muted-foreground">Roles</th>
            <th class="text-left px-4 py-3 font-medium text-muted-foreground">Status</th>
            <th class="text-left px-4 py-3 font-medium text-muted-foreground">Registered</th>
            <th class="px-4 py-3" />
          </tr>
        </thead>
        <tbody class="divide-y divide-border">
          <tr v-for="user in usersStore.users" :key="user.id" class="hover:bg-muted/20 transition-colors">
            <td class="px-4 py-3 text-muted-foreground font-mono">{{ user.id }}</td>
            <td class="px-4 py-3">{{ user.email }}</td>
            <td class="px-4 py-3">{{ user.username || '—' }}</td>
            <td class="px-4 py-3">
              <div class="flex items-center gap-1.5 flex-wrap">
                <span
                  v-if="user.roles.includes('ADMIN')"
                  class="inline-flex items-center rounded-full bg-primary/10 text-primary text-xs font-medium px-2 py-0.5"
                >Admin</span>
                <template v-for="role in user.roles.filter(r => r !== 'ADMIN' && r !== 'SUSPENDED')" :key="role">
                  <span class="inline-flex items-center rounded-full bg-muted text-muted-foreground text-xs font-medium px-2 py-0.5">
                    {{ role }}
                  </span>
                </template>
                <span
                  v-if="user.roles.includes('SUSPENDED')"
                  class="inline-flex items-center rounded-full bg-destructive/10 text-destructive text-xs font-medium px-2 py-0.5"
                >Suspended</span>
              </div>
            </td>
            <td class="px-4 py-3">
              <span v-if="user.roles.includes('SUSPENDED')" class="text-xs text-destructive">Suspended</span>
              <span v-else class="text-xs text-green-600 dark:text-green-400">Active</span>
            </td>
            <td class="px-4 py-3 text-muted-foreground">—</td>
            <td class="px-4 py-3">
              <div class="flex items-center gap-1 justify-end">
                <!-- Toggle Admin role -->
                <button
                  @click="toggleAdminRole(user)"
                  :disabled="togglingRole === user.id || isOwnAccount(user)"
                  :title="isOwnAccount(user) ? 'Cannot change your own admin role' : user.roles.includes('ADMIN') ? 'Revoke Admin' : 'Grant Admin'"
                  class="flex items-center justify-center h-7 w-7 rounded-lg text-foreground hover:bg-muted transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                >
                  <Icon v-if="user.roles.includes('ADMIN')" name="shield-check" class="h-4 w-4" />
                    <Icon v-else name="user-plus" class="h-4 w-4" />
                </button>

                <!-- Edit -->
                <button
                  @click="openEdit(user)"
                  title="Edit user"
                  class="flex items-center justify-center h-7 w-7 rounded-lg text-foreground hover:bg-muted transition-colors"
                >
                  <Icon name="pencil" class="h-4 w-4" />
                </button>

                <!-- Delete -->
                <button
                  @click="openDelete(user)"
                  :disabled="isOwnAccount(user)"
                  :title="isOwnAccount(user) ? 'Cannot delete your own account' : 'Delete user'"
                  class="flex items-center justify-center h-7 w-7 rounded-lg text-foreground hover:text-destructive hover:bg-destructive/10 transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                >
                  <Icon name="trash" class="h-4 w-4" />
                </button>
              </div>
            </td>
          </tr>
          <tr v-if="usersStore.users.length === 0">
            <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">No users found.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div v-if="lastPage > 1" class="mt-4 flex items-center justify-between text-sm">
      <span class="text-muted-foreground">Page {{ currentPage }} of {{ lastPage }}</span>
      <div class="flex gap-2">
        <button
          @click="loadPage(currentPage - 1)"
          :disabled="currentPage <= 1"
          class="inline-flex h-8 items-center justify-center rounded-lg border border-border bg-background px-3 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
        >
          Previous
        </button>
        <button
          @click="loadPage(currentPage + 1)"
          :disabled="currentPage >= lastPage"
          class="inline-flex h-8 items-center justify-center rounded-lg border border-border bg-background px-3 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
        >
          Next
        </button>
      </div>
    </div>
  </div>

  <!-- ── Create User Modal ────────────────────────────────────────────── -->
  <Modal v-model="showCreate" title="Create User" size="sm" @close="showCreate = false">
    <form @submit.prevent="createUser" class="flex flex-col gap-4">
      <div class="flex flex-col gap-1.5">
        <label for="create-email" class="text-sm font-medium">Email</label>
        <input
          id="create-email"
          v-model="createForm.email"
          type="email"
          required
          placeholder="user@example.com"
          class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
        />
      </div>
      <div class="flex flex-col gap-1.5">
        <label for="create-password" class="text-sm font-medium">Password</label>
        <input
          id="create-password"
          v-model="createForm.password"
          type="password"
          required
          placeholder="Min. 8 characters"
          class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
        />
      </div>
      <p v-if="createError" role="alert" class="text-xs text-destructive">{{ createError }}</p>
    </form>
    <template #footer>
      <div class="flex justify-end gap-2">
        <button
          @click="showCreate = false"
          class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
        >
          Cancel
        </button>
        <button
          @click="createUser"
          :disabled="creating || !createForm.email.trim() || createForm.password.length < 8"
          class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
        >
          {{ creating ? 'Creating…' : 'Create User' }}
        </button>
      </div>
    </template>
  </Modal>

  <!-- ── Edit User Modal ─────────────────────────────────────────────── -->
  <Modal v-model="isEditingOpen" :title="`Edit ${editingUser?.email}`" size="sm" @close="closeEdit">
    <form @submit.prevent="saveEdit" class="flex flex-col gap-4">
      <div class="flex flex-col gap-1.5">
        <label for="edit-username" class="text-sm font-medium">Username</label>
        <input
          id="edit-username"
          v-model="editForm.username"
          type="text"
          placeholder="Display name (optional)"
          class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
        />
      </div>
      <div class="flex items-start gap-3">
        <input
          id="edit-admin"
          v-model="editForm.isAdmin"
          type="checkbox"
          class="mt-0.5 h-4 w-4 rounded border-border bg-background text-primary focus:ring-1 focus:ring-ring"
        />
        <div class="flex flex-col gap-1">
          <label for="edit-admin" class="text-sm font-medium">Admin</label>
          <p class="text-xs text-muted-foreground">Full administrative access.</p>
        </div>
      </div>
      <div class="flex items-start gap-3">
        <input
          id="edit-suspended"
          v-model="editForm.suspended"
          type="checkbox"
          class="mt-0.5 h-4 w-4 rounded border-border bg-background text-primary focus:ring-1 focus:ring-ring"
        />
        <div class="flex flex-col gap-1">
          <label for="edit-suspended" class="text-sm font-medium">Suspended</label>
          <p class="text-xs text-muted-foreground">Suspended users cannot log in.</p>
        </div>
      </div>
      <p v-if="editError" role="alert" class="text-xs text-destructive">{{ editError }}</p>
    </form>
    <template #footer>
      <div class="flex justify-end gap-2">
        <button
          @click="isEditingOpen = false"
          class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
        >
          Cancel
        </button>
        <button
          @click="saveEdit"
          :disabled="savingEdit"
          class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
        >
          {{ savingEdit ? 'Saving…' : 'Save Changes' }}
        </button>
      </div>
    </template>
  </Modal>

  <!-- ── Delete Confirmation Modal ────────────────────────────────────── -->
  <Modal v-model="isDeleteOpen" title="Delete User" size="sm" @close="closeDelete">
    <div class="flex flex-col gap-3">
      <p class="text-sm text-muted-foreground">
        This will permanently delete the account
        <strong class="text-foreground">{{ deletingUser?.email }}</strong>.
        This action cannot be undone.
      </p>
      <p v-if="deleteError" role="alert" class="text-xs text-destructive">{{ deleteError }}</p>
    </div>
    <template #footer>
      <div class="flex justify-end gap-2">
        <button
          @click="deletingUser = null"
          class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
        >
          Cancel
        </button>
        <button
          @click="confirmDelete"
          :disabled="deleting"
          class="inline-flex h-9 items-center justify-center rounded-lg bg-destructive px-4 text-sm font-medium text-destructive-foreground shadow transition-colors hover:bg-destructive/90 disabled:pointer-events-none disabled:opacity-50"
        >
          {{ deleting ? 'Deleting…' : 'Delete User' }}
        </button>
      </div>
    </template>
  </Modal>
</template>
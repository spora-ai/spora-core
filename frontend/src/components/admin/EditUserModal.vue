<script setup lang="ts">
/**
 * EditUserModal — dialog for editing user details.
 */
import { ref, watch } from 'vue'
import type { User } from '@/types/user'
import { ApiError } from '@/api/client'
import { useUsersStore } from '@/stores/users'
import { useToast } from '@/composables/useToast'
import Modal from '@/components/Modal.vue'

const props = defineProps<{
  user: User | null
  modelValue: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
}>()

const usersStore = useUsersStore()
const toast = useToast()
const saving = ref(false)
const editError = ref<string | null>(null)

const form = ref({
  name: '',
  isAdmin: false,
  suspended: false,
  verified: false,
})

watch(() => props.user, (user) => {
  if (user) {
    form.value = {
      name: user.name ?? '',
      isAdmin: user.roles.includes('ADMIN'),
      suspended: user.roles.includes('SUSPENDED'),
      verified: user.verified,
    }
    editError.value = null
  }
}, { immediate: true })

async function save(): Promise<void> {
  if (!props.user) return
  saving.value = true
  editError.value = null
  try {
    const wasAdmin = props.user.roles.includes('ADMIN')
    const isAdmin = form.value.isAdmin

    await usersStore.updateUser(props.user.id, {
      name: form.value.name,
      suspended: form.value.suspended,
      verified: form.value.verified,
    })

    if (wasAdmin && !isAdmin) {
      await usersStore.revokeRole(props.user.id, 'ADMIN')
    } else if (!wasAdmin && isAdmin) {
      await usersStore.grantRole(props.user.id, 'ADMIN')
    }

    toast.success('User updated.')
    emit('update:modelValue', false)
  } catch (e) {
    editError.value = e instanceof ApiError ? e.message : 'Failed to update user.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Modal :model-value="modelValue" :title="`Edit ${user?.email}`" size="sm" @update:model-value="emit('update:modelValue', $event)">
    <form @submit.prevent="save" class="flex flex-col gap-4">
      <div class="flex flex-col gap-1.5">
        <label for="edit-name" class="text-sm font-medium">Name</label>
        <input
          id="edit-name"
          v-model="form.name"
          type="text"
          placeholder="Display name (optional)"
          class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
        />
      </div>
      <div class="flex items-start gap-3">
        <input
          id="edit-admin"
          v-model="form.isAdmin"
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
          v-model="form.suspended"
          type="checkbox"
          class="mt-0.5 h-4 w-4 rounded border-border bg-background text-primary focus:ring-1 focus:ring-ring"
        />
        <div class="flex flex-col gap-1">
          <label for="edit-suspended" class="text-sm font-medium">Suspended</label>
          <p class="text-xs text-muted-foreground">Suspended users cannot log in.</p>
        </div>
      </div>
      <div class="flex items-start gap-3">
        <input
          id="edit-verified"
          v-model="form.verified"
          type="checkbox"
          class="mt-0.5 h-4 w-4 rounded border-border bg-background text-primary focus:ring-1 focus:ring-ring"
        />
        <div class="flex flex-col gap-1">
          <label for="edit-verified" class="text-sm font-medium">Verified</label>
          <p class="text-xs text-muted-foreground">Verified users have confirmed their email.</p>
        </div>
      </div>
      <p v-if="editError" role="alert" class="text-xs text-destructive">{{ editError }}</p>
    </form>
    <template #footer>
      <div class="flex justify-end gap-2">
        <button
          @click="emit('update:modelValue', false)"
          class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
        >
          Cancel
        </button>
        <button
          @click="save"
          :disabled="saving"
          class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
        >
          {{ saving ? 'Saving…' : 'Save Changes' }}
        </button>
      </div>
    </template>
  </Modal>
</template>

<script setup lang="ts">
/**
 * DeleteUserModal — confirmation dialog for deleting a user.
 */
import { ref } from 'vue'
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
const deleting = ref(false)
const deleteError = ref<string | null>(null)

async function confirmDelete(): Promise<void> {
  if (!props.user) return
  deleting.value = true
  deleteError.value = null
  try {
    await usersStore.deleteUser(props.user.id)
    toast.success('User deleted.')
    emit('update:modelValue', false)
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
</script>

<template>
  <Modal :model-value="modelValue" title="Delete User" size="sm" @update:model-value="emit('update:modelValue', $event)">
    <div class="flex flex-col gap-3">
      <p class="text-sm text-muted-foreground">
        This will permanently delete the account
        <strong class="text-foreground">{{ user?.email }}</strong>.
        This action cannot be undone.
      </p>
      <p v-if="deleteError" role="alert" class="text-xs text-destructive">{{ deleteError }}</p>
    </div>
    <template #footer>
      <div class="flex justify-end gap-2">
        <button
          @click="emit('update:modelValue', false)"
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

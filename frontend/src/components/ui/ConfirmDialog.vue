<script setup lang="ts">
/**
 * ConfirmDialog — modal confirmation overlay.
 *
 * Usage:
 *   const confirmed = await confirm('Delete this?')
 *   // returns true if user confirmed, false if cancelled
 */
import { ref } from 'vue'
import Modal from '@/components/Modal.vue'

const modelValue = ref(false)
const message = ref('')
const title = ref('Confirm')
const confirmLabel = ref('Delete')
// eslint-disable-next-line no-unused-vars -- resolve expects a callback with value param, unused since confirm() always passes true
let resolve: ((value: boolean) => void) | null = null

function open(msg: string, dialogTitle = 'Confirm', confirmBtnLabel = 'Delete'): Promise<boolean> {
  message.value = msg
  title.value = dialogTitle
  confirmLabel.value = confirmBtnLabel
  modelValue.value = true
  return new Promise((res) => { resolve = res as /* eslint-disable-line no-unused-vars */ unknown as (value: boolean) => void })
}

function confirm(): void {
  modelValue.value = false
  resolve?.(true)
}

function cancel(): void {
  modelValue.value = false
  resolve?.(false)
}

defineExpose({ open })
</script>

<template>
  <Modal
    v-model="modelValue"
    :title="title"
    size="sm"
    :backdropClosable="true"
    @update:modelValue="(v) => !v && cancel()"
  >
    <p class="text-sm text-muted-foreground">{{ message }}</p>

    <template #footer>
      <div class="flex justify-end gap-2 w-full">
        <button
          @click="cancel"
          class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
        >
          Cancel
        </button>
        <button
          @click="confirm"
          class="inline-flex h-9 items-center justify-center rounded-lg bg-destructive px-4 text-sm font-medium text-destructive-foreground shadow-sm transition-colors hover:bg-destructive/90"
        >
          {{ confirmLabel }}
        </button>
      </div>
    </template>
  </Modal>
</template>
<script setup lang="ts">
import Modal from '@/components/Modal.vue'

defineProps<{
  toolName: string | null
  missingRequired: string[]
}>()

const emit = defineEmits<{
  configure: []
  close: []
}>()
</script>

<template>
  <Modal
    :modelValue="toolName !== null"
    title="Configuration Required"
    size="sm"
    @update:modelValue="!$event && emit('close')"
    @close="emit('close')"
  >
    <div class="space-y-3">
      <p class="text-sm">
        The tool <strong>{{ toolName }}</strong> has no configuration set.
      </p>
      <p class="text-xs text-muted-foreground">
        Missing settings: {{ missingRequired.join(', ') }}.
        Configure this tool before enabling it.
      </p>
    </div>
    <div class="flex justify-end gap-2 mt-5">
      <button
        type="button"
        @click="emit('close')"
        class="px-4 py-2 text-sm border border-border rounded-lg hover:bg-muted transition-colors"
      >
        Close
      </button>
      <button
        type="button"
        @click="emit('configure')"
        class="px-4 py-2 text-sm bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors"
      >
        Open Configuration
      </button>
    </div>
  </Modal>
</template>
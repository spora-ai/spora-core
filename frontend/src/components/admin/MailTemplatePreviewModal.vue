<script setup lang="ts">
/**
 * MailTemplatePreviewModal — params form + Generate Preview + rendered result.
 */
import Icon from '@/components/ui/Icon.vue'
import { formatPlaceholder } from '@/composables/useMailTemplates'
import type { PreviewPayload } from '@/types/mailTemplate'

defineProps<{
  modelValue: boolean
  paramKeys: string[]
  params: Record<string, string>
  loading: boolean
  result: PreviewPayload | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  'update:param': [key: string, value: string]
  generate: []
}>()

function close(): void {
  emit('update:modelValue', false)
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="modelValue"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
      @click.self="close"
    >
      <div class="w-full max-w-2xl mx-4 rounded-2xl border border-border bg-background shadow-xl flex flex-col max-h-[90vh]">
        <div class="px-6 py-4 border-b border-border flex items-center justify-between shrink-0">
          <h2 class="text-base font-semibold">Preview Template</h2>
          <button
            @click="close"
            class="text-muted-foreground hover:text-foreground transition-colors"
          >
            <Icon name="x" class="h-5 w-5" />
          </button>
        </div>

        <div class="px-6 py-4 overflow-y-auto flex flex-col gap-4">
          <div class="grid grid-cols-2 gap-3">
            <div v-for="key in paramKeys" :key="key" class="flex flex-col gap-1.5">
              <label :for="`preview-${key}`" class="text-xs font-medium">{{ key }}</label>
              <input
                :id="`preview-${key}`"
                :value="params[key]"
                @input="emit('update:param', key, ($event.target as HTMLInputElement).value)"
                type="text"
                class="w-full rounded-lg border border-border bg-background px-3 py-1.5 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                :placeholder="formatPlaceholder(key)"
              />
            </div>
          </div>

          <button
            @click="emit('generate')"
            :disabled="loading"
            class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:opacity-50"
          >
            {{ loading ? 'Rendering…' : 'Generate Preview' }}
          </button>

          <template v-if="result">
            <div class="border-t border-border pt-4 flex flex-col gap-3">
              <div class="flex flex-col gap-1.5">
                <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Subject</p>
                <p class="text-sm font-medium">{{ result.subject }}</p>
              </div>
              <div class="flex flex-col gap-1.5">
                <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Plain Text</p>
                <pre class="text-sm bg-muted rounded-lg p-3 whitespace-pre-wrap font-mono max-h-40 overflow-y-auto">{{ result.body_text }}</pre>
              </div>
              <div v-if="result.body_html" class="flex flex-col gap-1.5">
                <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">HTML</p>
                <pre class="text-sm bg-muted rounded-lg p-3 whitespace-pre-wrap font-mono max-h-40 overflow-y-auto">{{ result.body_html }}</pre>
              </div>
            </div>
          </template>
        </div>

        <div class="px-6 py-4 border-t border-border flex items-center justify-end shrink-0">
          <button
            @click="close"
            class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium shadow transition-colors hover:bg-muted"
          >
            Close
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
